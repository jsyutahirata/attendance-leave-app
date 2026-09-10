<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/LeaveImportService.php';

use App\LeaveImportService;

$legacyParser = new ReflectionMethod(LeaveImportService::class, 'parseLegacySummary');
$standardParser = new ReflectionMethod(LeaveImportService::class, 'parseStandard');
$normalizeName = new ReflectionMethod(LeaveImportService::class, 'normalizeName');
$passed = 0;

function employeeLookup(array $names): array
{
    $lookup = ['names' => [], 'codes' => []];
    foreach ($names as $index => $name) {
        $key = mb_strtolower(preg_replace('/[\s　]+/u', '', trim($name)) ?? trim($name));
        $lookup['names'][$key][] = ['id' => $index + 1, 'full_name' => $name, 'employee_code' => ''];
    }
    return $lookup;
}

function legacyRows(string $asOf, array $employees): array
{
    $dateRow = array_fill(0, 11, '');
    $dateRow[9] = str_replace('-', '/', $asOf);
    $header = ['', '', '入社年', '入社月', '更新月', '前年残り', '今年付与', '総数', '', '残数', '使用'];
    $rows = [$dateRow, $header];
    foreach ($employees as $employee) {
        $rows[] = ['', $employee['name'], (string)$employee['hire_year'], (string)$employee['hire_month'],
            (string)$employee['renewal_month'], (string)$employee['previous'], (string)$employee['current'],
            (string)$employee['total'], '', (string)$employee['balance'], (string)($employee['usage'] ?? '')];
    }
    return $rows;
}

function parseLegacy(ReflectionMethod $parser, array $rows, array $names, string $currentMonth): array
{
    $result = $parser->invoke(null, $rows, employeeLookup($names), $currentMonth);
    if (!is_array($result)) throw new RuntimeException('月別CSVとして判定されませんでした。');
    return $result['rows'];
}

function expectFloat(float $expected, mixed $actual, string $message): void
{
    if (abs($expected - (float)$actual) > 0.001) {
        throw new RuntimeException($message . " expected={$expected} actual=" . (float)$actual);
    }
}

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function pass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS {$name}\n";
}

// 1. 社内ルールどおり、今年付与分を先に消化する。
$rows = parseLegacy($legacyParser, legacyRows('2026-09', [[
    'name' => 'Aさん', 'hire_year' => 2018, 'hire_month' => 6, 'renewal_month' => 12,
    'previous' => 9, 'current' => 20, 'total' => 29, 'balance' => 3,
]]), ['Aさん'], '2026-09');
expectFloat(0, $rows[0]['remaining_days'], '今年付与分の残数');
expectFloat(3, $rows[1]['remaining_days'], '前年繰越分の残数');
pass('今年付与分から先に消化');

// 2. 前年繰越がない通常ケース。
$rows = parseLegacy($legacyParser, legacyRows('2026-09', [[
    'name' => 'Bさん', 'hire_year' => 2024, 'hire_month' => 4, 'renewal_month' => 10,
    'previous' => 0, 'current' => 12, 'total' => 12, 'balance' => 5,
]]), ['Bさん'], '2026-09');
expectFloat(5, $rows[0]['remaining_days'], '繰越なしの今年残数');
expectFloat(0, $rows[1]['remaining_days'], '繰越なしの前年残数');
pass('前年繰越なし');

// 3. 初回付与前は残数欄ではなく、各月の使用欄から前借日数を求める。
$dateRow = array_fill(0, 13, '');
$dateRow[9] = '2026/8';
$dateRow[11] = '2026/9';
$header = ['', '', '入社年', '入社月', '更新月', '前年残り', '今年付与', '総数', '', '残数', '使用', '残数', '使用'];
$data = ['', 'Cさん', '2026', '4', '10', '0', '10', '10', '', '0', '-4', '0', ''];
$rows = parseLegacy($legacyParser, [$dateRow, $header, $data], ['Cさん'], '2026-09');
expectFloat(10, $rows[0]['days'], '将来の初回付与日数');
expectFloat(6, $rows[0]['remaining_days'], '前借を引いた付与後残数');
expectFloat(4, $rows[0]['advance_days'], '8月の前借日数');
expectSame('2026-10-01', $rows[0]['granted_on'], '入社6か月後の付与日');
expectSame('2028-09-30', $rows[0]['expires_on'], '初回付与分の有効期限');
pass('初回付与前の月別前借');

// 4. 0.5日単位を維持する。
$rows = parseLegacy($legacyParser, legacyRows('2026-11', [[
    'name' => '半日さん', 'hire_year' => 2020, 'hire_month' => 1, 'renewal_month' => 1,
    'previous' => 1.5, 'current' => 10, 'total' => 11.5, 'balance' => 11,
]]), ['半日さん'], '2026-11');
expectFloat(9.5, $rows[0]['remaining_days'], '半日消化後の今年残数');
expectFloat(1.5, $rows[1]['remaining_days'], '半日消化後の前年残数');
pass('半日単位');

// 5. 更新月の直前・当月・直後で付与年度が切り替わる。
foreach ([['2026-09', 2025], ['2026-10', 2026], ['2026-11', 2026]] as [$asOf, $expectedYear]) {
    $rows = parseLegacy($legacyParser, legacyRows($asOf, [[
        'name' => '境界さん', 'hire_year' => 2020, 'hire_month' => 4, 'renewal_month' => 10,
        'previous' => 0, 'current' => 10, 'total' => 10, 'balance' => 10,
    ]]), ['境界さん'], $asOf);
    expectSame($expectedYear, $rows[0]['grant_year'], "{$asOf}の付与年度");
}
pass('更新月の境界');

// 6. 前借が初回付与日数を超えた場合は、付与後残数もマイナスになる。
$data = ['', '超過さん', '2026', '4', '10', '0', '10', '10', '', '0', '-12', '0', ''];
$rows = parseLegacy($legacyParser, [$dateRow, $header, $data], ['超過さん'], '2026-09');
expectFloat(12, $rows[0]['advance_days'], '超過前借日数');
expectFloat(-2, $rows[0]['remaining_days'], '超過前借後の残数');
pass('前借超過');

// 7. 通常の残数0はスキップする。
$rows = parseLegacy($legacyParser, legacyRows('2026-11', [[
    'name' => 'ゼロさん', 'hire_year' => 2020, 'hire_month' => 1, 'renewal_month' => 1,
    'previous' => 0, 'current' => 10, 'total' => 10, 'balance' => 0,
]]), ['ゼロさん'], '2026-11');
expectSame('残数0日のためスキップ', $rows[0]['skip_reason'], '残数0の判定');
pass('通常残数0');

// 8. 未登録社員でもCSVの残数は0に潰さず、照合エラーを表示する。
$rows = parseLegacy($legacyParser, legacyRows('2026-09', [[
    'name' => '未登録さん', 'hire_year' => 2020, 'hire_month' => 1, 'renewal_month' => 1,
    'previous' => 2, 'current' => 10, 'total' => 12, 'balance' => 7,
]]), [], '2026-09');
expectFloat(5, $rows[0]['remaining_days'], '未登録社員の今年残数表示');
expectFloat(2, $rows[1]['remaining_days'], '未登録社員の前年残数表示');
if (!$rows[0]['errors']) throw new RuntimeException('未登録社員エラーがありません。');
pass('未登録社員の値保持');

// 9. 同姓同名は安全のためエラーにする。
$lookup = employeeLookup(['同姓同名']);
$lookup['names']['同姓同名'][] = ['id' => 99, 'full_name' => '同姓同名', 'employee_code' => ''];
$result = $legacyParser->invoke(null, legacyRows('2026-09', [[
    'name' => '同姓同名', 'hire_year' => 2020, 'hire_month' => 1, 'renewal_month' => 1,
    'previous' => 0, 'current' => 10, 'total' => 10, 'balance' => 10,
]]), $lookup, '2026-09');
if (!str_contains(implode(' ', $result['rows'][0]['errors']), '複数登録')) throw new RuntimeException('同姓同名エラーがありません。');
pass('同姓同名');

// 10. 氏名の半角・全角スペースは無視して照合する。
$normalized = $normalizeName->invoke(null, ' 山田　太 郎 ');
expectSame('山田太郎', $normalized, '氏名の空白除去');
pass('氏名正規化');

// 11. 不正な更新月と0.5日単位外の値を拒否する。
$rows = parseLegacy($legacyParser, legacyRows('2026-09', [[
    'name' => '不正さん', 'hire_year' => 2020, 'hire_month' => 1, 'renewal_month' => 13,
    'previous' => 0, 'current' => 10, 'total' => 10, 'balance' => 9.7,
]]), ['不正さん'], '2026-09');
$errors = implode(' ', $rows[0]['errors']);
if (!str_contains($errors, '更新月') || !str_contains($errors, '0.5日単位')) throw new RuntimeException('入力値エラーが不足しています。');
pass('不正値');

// 12. 標準CSVでも半日残数と2年間の期限上限を検証する。
$standardRows = [
    ['氏名', '対象年度', '残日数', '付与日', '有効期限', '更新月', '備考'],
    ['標準さん', '2026', '3.5', '2026-04-01', '2028-03-31', '4', '移行'],
    ['標準さん', '2026', '1', '2026-04-01', '2028-04-01', '4', '期限超過'],
];
$parsed = $standardParser->invoke(null, $standardRows, employeeLookup(['標準さん']));
expectFloat(3.5, $parsed['rows'][0]['remaining_days'], '標準CSVの半日残数');
if ($parsed['rows'][0]['errors']) throw new RuntimeException('正しい標準CSVがエラーになりました。');
if (!str_contains(implode(' ', $parsed['rows'][1]['errors']), '2年後')) throw new RuntimeException('期限超過エラーがありません。');
pass('標準CSV');

echo "Leave import tests passed: {$passed} patterns.\n";
