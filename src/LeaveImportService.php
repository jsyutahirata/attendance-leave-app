<?php
declare(strict_types=1);

namespace App;

use DomainException;

final class LeaveImportService
{
    private const MAX_BYTES = 1048576;
    private const MAX_ROWS = 1000;
    private const STANDARD_HEADERS = [
        '氏名' => 'employee_name',
        '社員名' => 'employee_name',
        '社員番号' => 'employee_code',
        '対象年度' => 'grant_year',
        '残日数' => 'days',
        '付与日' => 'granted_on',
        '有効期限' => 'expires_on',
        '更新月' => 'renewal_month',
        '備考' => 'note',
    ];

    public static function preview(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($file['tmp_name'])) {
            throw new DomainException('CSVファイルを選択してください。');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_BYTES) throw new DomainException('CSVは1MB以下にしてください。');
        $raw = file_get_contents((string)$file['tmp_name']);
        if ($raw === false || $raw === '') throw new DomainException('CSVを読み込めませんでした。');
        $encoding = mb_detect_encoding($raw, ['UTF-8', 'SJIS-win'], true);
        if ($encoding === false) throw new DomainException('CSVの文字コードを判定できません。UTF-8またはShift-JISで保存してください。');
        if ($encoding !== 'UTF-8') $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $csvRows = self::csvRows($raw);
        $lookup = self::employeeLookup();

        $legacy = self::parseLegacySummary($csvRows, $lookup);
        $parsed = $legacy ?? self::parseStandard($csvRows, $lookup);
        if (!$parsed['rows']) throw new DomainException('取り込み対象のデータ行がありません。');
        $rows = self::markDuplicates($parsed['rows']);
        return [
            'source_name' => mb_substr((string)($file['name'] ?? 'import.csv'), 0, 200),
            'source_hash' => hash('sha256', $raw),
            'source_format' => $parsed['format'],
            'as_of' => $parsed['as_of'] ?? null,
            'created_at' => time(),
            'rows' => $rows,
            'valid_count' => count(array_filter($rows, static fn(array $row): bool => !$row['errors'] && !$row['duplicate'] && $row['skip_reason'] === '')),
            'error_count' => count(array_filter($rows, static fn(array $row): bool => (bool)$row['errors'])),
            'duplicate_count' => count(array_filter($rows, static fn(array $row): bool => $row['duplicate'])),
            'skip_count' => count(array_filter($rows, static fn(array $row): bool => $row['skip_reason'] !== '')),
        ];
    }

    private static function csvRows(string $raw): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new DomainException('CSVを解析できませんでした。');
        fwrite($stream, $raw); rewind($stream); $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) $rows[] = array_map(static fn($value): string => trim((string)$value), $row);
        fclose($stream);
        return $rows;
    }

    private static function employeeLookup(): array
    {
        $employees = Database::connection()->query('SELECT e.id, e.full_name, e.employee_code FROM employees e JOIN users u ON u.employee_id=e.id')->fetchAll();
        $names = []; $codes = [];
        foreach ($employees as $employee) {
            $names[self::normalizeName((string)$employee['full_name'])][] = $employee;
            if ((string)$employee['employee_code'] !== '') $codes[(string)$employee['employee_code']][] = $employee;
        }
        return ['names' => $names, 'codes' => $codes];
    }

    private static function resolveEmployee(array $lookup, string $name, string $code, array &$errors): ?array
    {
        $matches = $code !== '' ? ($lookup['codes'][$code] ?? []) : ($lookup['names'][self::normalizeName($name)] ?? []);
        $label = $code !== '' ? "社員番号「{$code}」" : "氏名「{$name}」";
        if (!$matches) { $errors[] = "{$label}が登録されていません"; return null; }
        if (count($matches) > 1) { $errors[] = "{$label}が複数登録されているため特定できません"; return null; }
        return $matches[0];
    }

    private static function normalizeName(string $name): string
    {
        return mb_strtolower(preg_replace('/[\s　]+/u', '', trim($name)) ?? trim($name));
    }

    private static function parseLegacySummary(array $csvRows, array $lookup, ?string $currentMonth = null): ?array
    {
        $currentMonth ??= date('Y-m');
        $candidates = [];
        foreach ($csvRows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                if (preg_match('/\A(\d{4})\/(\d{1,2})\z/', $value, $match)) {
                    $month = sprintf('%04d-%02d', (int)$match[1], (int)$match[2]);
                    if ($month <= $currentMonth) $candidates[] = ['month' => $month, 'label' => $value, 'row' => $rowIndex, 'column' => $columnIndex];
                }
            }
        }
        if (!$candidates) return null;
        usort($candidates, static fn(array $a, array $b): int => $b['month'] <=> $a['month']);
        $candidate = $candidates[0];
        $header = $csvRows[$candidate['row'] + 1] ?? [];
        if (!in_array('入社年', $header, true) || !in_array('更新月', $header, true) || !in_array('総数', $header, true)) return null;
        $updateColumn = array_search('更新月', $header, true);
        $hireYearColumn = array_search('入社年', $header, true);
        $hireMonthColumn = array_search('入社月', $header, true);
        $previousColumn = array_search('前年残り', $header, true);
        $currentColumn = array_search('今年付与', $header, true);
        $totalColumn = array_search('総数', $header, true);
        if ($updateColumn === false || $previousColumn === false || $currentColumn === false || $totalColumn === false) return null;
        $rows = [];
        for ($index = $candidate['row'] + 2; $index < count($csvRows); $index++) {
            $columns = $csvRows[$index];
            $name = trim((string)($columns[1] ?? ''));
            if ($name === '') break;
            if (count($rows) >= self::MAX_ROWS) throw new DomainException('CSVは社員1000名以下にしてください。');
            $errors = []; $calculationErrors = [];
            $employee = self::resolveEmployee($lookup, $name, '', $errors);
            $hireYear = filter_var($columns[$hireYearColumn] ?? '', FILTER_VALIDATE_INT);
            $hireMonth = filter_var($columns[$hireMonthColumn] ?? '', FILTER_VALIDATE_INT);
            $updateMonth = filter_var($columns[$updateColumn] ?? '', FILTER_VALIDATE_INT);
            if ($hireYear === false || $hireYear < 1900 || $hireYear > (int)date('Y') + 1 || $hireMonth === false || $hireMonth < 1 || $hireMonth > 12) {
                $calculationErrors[] = '入社年月が正しくありません';
            }
            if ($updateMonth === false || $updateMonth < 1 || $updateMonth > 12) {
                $calculationErrors[] = '更新月が正しくありません';
            }
            $rawDays = trim((string)($columns[$candidate['column']] ?? ''));
            $days = is_numeric($rawDays) ? (float)$rawDays : -1.0;
            if ($rawDays === '' || $days < 0 || $days > 999 || fmod($days * 2, 1.0) !== 0.0) {
                $calculationErrors[] = $candidate['label'] . 'の残数は0.5日単位で入力してください';
            }
            $previousGranted = trim((string)($columns[$previousColumn] ?? ''));
            $currentGranted = trim((string)($columns[$currentColumn] ?? ''));
            $totalGranted = trim((string)($columns[$totalColumn] ?? ''));
            $previousDays = $previousGranted === '' ? 0.0 : (is_numeric($previousGranted) ? (float)$previousGranted : -1.0);
            $currentDaysAtStart = $currentGranted === '' ? 0.0 : (is_numeric($currentGranted) ? (float)$currentGranted : -1.0);
            $totalDaysAtStart = $totalGranted === '' ? $previousDays + $currentDaysAtStart : (is_numeric($totalGranted) ? (float)$totalGranted : -1.0);
            if ($previousDays < 0 || $currentDaysAtStart < 0 || $totalDaysAtStart < 0) {
                $calculationErrors[] = '前年残り・今年付与・総数を数値で入力してください';
            }
            if (!$calculationErrors && abs(($previousDays + $currentDaysAtStart) - $totalDaysAtStart) > 0.01) {
                $calculationErrors[] = '前年残りと今年付与の合計が総数と一致しません';
            }
            if (!$calculationErrors && $days > $totalDaysAtStart) {
                $calculationErrors[] = '月別残数が総数を超えています';
            }
            $errors = array_merge($errors, $calculationErrors);
            $asOfYear = (int)substr($candidate['month'], 0, 4); $asOfMonth = (int)substr($candidate['month'], 5, 2);
            $grantYear = $updateMonth !== false ? $asOfYear - ($asOfMonth < (int)$updateMonth ? 1 : 0) : $asOfYear;
            $hireMonthStart = !$calculationErrors ? new \DateTimeImmutable(sprintf('%04d-%02d-01', (int)$hireYear, (int)$hireMonth)) : null;
            $firstGrantOn = $hireMonthStart?->modify('+6 months');
            $asOfMonthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $asOfYear, $asOfMonth));
            $isAdvanceGrant = !$calculationErrors
                && $firstGrantOn !== null
                && $asOfMonthStart >= $hireMonthStart
                && $asOfMonthStart < $firstGrantOn
                && $currentDaysAtStart > 0.0;
            if ($isAdvanceGrant) $grantYear = (int)$firstGrantOn->format('Y');
            $advanceDays = 0.0;
            if ($isAdvanceGrant) {
                $monthHeadings = $csvRows[$candidate['row']];
                foreach ($monthHeadings as $monthColumn => $monthLabel) {
                    if (!preg_match('/\A(\d{4})\/(\d{1,2})\z/', $monthLabel, $monthMatch)) continue;
                    $usageMonth = new \DateTimeImmutable(sprintf('%04d-%02d-01', (int)$monthMatch[1], (int)$monthMatch[2]));
                    if ($usageMonth < $hireMonthStart || $usageMonth > $asOfMonthStart) continue;
                    $rawUsage = trim((string)($columns[$monthColumn + 1] ?? ''));
                    if ($rawUsage === '') continue;
                    if (!is_numeric($rawUsage) || fmod(((float)$rawUsage) * 2, 1.0) !== 0.0) {
                        $message = $monthLabel . 'の使用日数は0.5日単位で入力してください';
                        $calculationErrors[] = $message;
                        $errors[] = $message;
                        continue;
                    }
                    $advanceDays += abs((float)$rawUsage);
                }
            }
            $usedDays = !$calculationErrors
                ? ($isAdvanceGrant ? $advanceDays : max(0.0, $totalDaysAtStart - $days))
                : 0.0;
            // 社内運用では今期付与分を先に消化し、その後に前年繰越分を消化する。
            $currentRemaining = !$calculationErrors
                ? ($isAdvanceGrant ? $currentDaysAtStart - $usedDays : max(0.0, $currentDaysAtStart - $usedDays))
                : 0.0;
            $usedAfterCurrent = !$calculationErrors ? max(0.0, $usedDays - $currentDaysAtStart) : 0.0;
            $carryoverRemaining = !$calculationErrors ? max(0.0, $previousDays - $usedAfterCurrent) : 0.0;
            foreach ([
                ['type' => '今年付与分', 'year' => $grantYear, 'days' => $currentRemaining],
                ['type' => '前年繰越分', 'year' => $grantYear - 1, 'days' => $carryoverRemaining],
            ] as $component) {
                $cycleGrantedOn = $component['type'] === '今年付与分' && $isAdvanceGrant
                    ? $firstGrantOn->format('Y-m-d')
                    : sprintf('%04d-%02d-01', $component['year'], min(12, max(1, (int)$updateMonth)));
                $grantedOn = $cycleGrantedOn;
                $expiresOn = (new \DateTimeImmutable($cycleGrantedOn))->modify('+2 years -1 day')->format('Y-m-d');
                $isAdvanceComponent = $component['type'] === '今年付与分' && $isAdvanceGrant;
                $rows[] = self::finishRow([
                    'line' => $index + 1, 'employee' => $employee, 'source_name' => $name, 'source_code' => '',
                    'grant_year' => $component['year'], 'days' => $isAdvanceComponent ? $currentDaysAtStart : $component['days'],
                    'remaining_days' => $component['days'], 'advance_days' => $isAdvanceComponent ? $advanceDays : 0.0,
                    'granted_on' => $grantedOn, 'expires_on' => $expiresOn,
                    'renewal_month' => $updateMonth === false ? null : (int)$updateMonth,
                    'balance_type' => $component['type'] . ($isAdvanceComponent ? '（前借）' : ''),
                    'note' => '有給使用状況 ' . $candidate['label'] . '時点' . ($isAdvanceComponent ? '（初回付与前の前借）' : ''), 'errors' => $errors,
                    'skip_reason' => !$errors && !$isAdvanceComponent && $component['days'] === 0.0 ? '残数0日のためスキップ' : '',
                ]);
            }
        }
        if (!$rows) return null;
        return ['format' => '有給使用状況（月別表）', 'as_of' => $candidate['label'], 'rows' => $rows];
    }

    private static function parseStandard(array $csvRows, array $lookup): array
    {
        $headerIndex = null; $keys = [];
        foreach ($csvRows as $index => $row) {
            $mapped = array_map(static fn(string $heading): ?string => self::STANDARD_HEADERS[$heading] ?? null, $row);
            if (in_array('days', $mapped, true) && (in_array('employee_name', $mapped, true) || in_array('employee_code', $mapped, true))) {
                $headerIndex = $index; $keys = $mapped; break;
            }
        }
        if ($headerIndex === null) throw new DomainException('CSV形式を判定できません。添付の月別表または見本CSVを使用してください。');
        foreach (['grant_year', 'days', 'granted_on', 'expires_on'] as $required) {
            if (!in_array($required, $keys, true)) throw new DomainException('CSVの見出しが不足しています。見本CSVを使用してください。');
        }
        $rows = [];
        for ($index = $headerIndex + 1; $index < count($csvRows); $index++) {
            $columns = $csvRows[$index];
            if (count(array_filter($columns, static fn(string $value): bool => $value !== '')) === 0) continue;
            if (count($rows) >= self::MAX_ROWS) throw new DomainException('CSVは見出しを除いて1000行以下にしてください。');
            $data = ['employee_name' => '', 'employee_code' => '', 'grant_year' => '', 'days' => '', 'granted_on' => '', 'expires_on' => '', 'renewal_month' => '', 'note' => ''];
            foreach ($keys as $column => $key) if ($key !== null) $data[$key] = trim((string)($columns[$column] ?? ''));
            $errors = [];
            if ($data['employee_name'] === '' && $data['employee_code'] === '') $errors[] = '氏名または社員番号が空です';
            $employee = null;
            if ($data['employee_name'] !== '' || $data['employee_code'] !== '') $employee = self::resolveEmployee($lookup, $data['employee_name'], $data['employee_code'], $errors);
            $year = filter_var($data['grant_year'], FILTER_VALIDATE_INT);
            if ($year === false || $year < 2000 || $year > (int)date('Y') + 1) $errors[] = '対象年度が正しくありません';
            $days = is_numeric($data['days']) ? (float)$data['days'] : -1.0;
            if ($days < 0 || $days > 999 || fmod($days * 2, 1.0) !== 0.0) $errors[] = '残日数は0以上の0.5日単位で入力してください';
            foreach (['granted_on' => '付与日', 'expires_on' => '有効期限'] as $key => $label) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $data[$key]);
                if (!$date || $date->format('Y-m-d') !== $data[$key]) $errors[] = "{$label}はYYYY-MM-DD形式で入力してください";
            }
            if (!$errors && $data['expires_on'] < $data['granted_on']) $errors[] = '有効期限が付与日より前です';
            $maximumExpiry = !$errors ? (new \DateTimeImmutable($data['granted_on']))->modify('+2 years -1 day')->format('Y-m-d') : '';
            if (!$errors && $data['expires_on'] > $maximumExpiry) $errors[] = '有効期限は付与日の2年後の前日までです';
            $renewalMonth = $data['renewal_month'] === '' ? null : filter_var($data['renewal_month'], FILTER_VALIDATE_INT);
            if ($renewalMonth !== null && ($renewalMonth === false || $renewalMonth < 1 || $renewalMonth > 12)) $errors[] = '更新月が正しくありません';
            $rows[] = self::finishRow([
                'line' => $index + 1, 'employee' => $employee, 'source_name' => $data['employee_name'], 'source_code' => $data['employee_code'],
                'grant_year' => $year === false ? 0 : (int)$year, 'days' => max(0.0, $days), 'granted_on' => $data['granted_on'], 'expires_on' => $data['expires_on'],
                'renewal_month' => $renewalMonth === false ? null : $renewalMonth, 'balance_type' => '移行残数',
                'note' => mb_substr($data['note'], 0, 180), 'errors' => $errors, 'skip_reason' => !$errors && $days === 0.0 ? '残数0日のためスキップ' : '',
            ]);
        }
        return ['format' => '標準（1行1残数）', 'as_of' => null, 'rows' => $rows];
    }

    private static function finishRow(array $data): array
    {
        $employee = $data['employee'];
        return [
            'line' => (int)$data['line'], 'employee_id' => $employee ? (int)$employee['id'] : null,
            'employee_code' => (string)$data['source_code'], 'employee_name' => $employee['full_name'] ?? (string)$data['source_name'],
            'grant_year' => (int)$data['grant_year'], 'days' => (float)$data['days'], 'granted_on' => (string)$data['granted_on'],
            'expires_on' => (string)$data['expires_on'], 'note' => (string)$data['note'], 'errors' => $data['errors'],
            'renewal_month' => $data['renewal_month'] ?? null, 'balance_type' => (string)($data['balance_type'] ?? '移行残数'),
            'remaining_days' => (float)($data['remaining_days'] ?? $data['days']), 'advance_days' => (float)($data['advance_days'] ?? 0),
            'duplicate' => false, 'skip_reason' => (string)$data['skip_reason'],
        ];
    }

    private static function markDuplicates(array $rows): array
    {
        $stmt = Database::connection()->prepare('SELECT id FROM leave_grants WHERE employee_id=? AND grant_year=? AND granted_on=? AND days=? AND expires_on=? LIMIT 1');
        foreach ($rows as &$row) {
            if ($row['errors'] || $row['skip_reason'] !== '' || !$row['employee_id']) continue;
            $stmt->execute([(int)$row['employee_id'], (int)$row['grant_year'], $row['granted_on'], (float)$row['days'], $row['expires_on']]);
            $row['duplicate'] = (bool)$stmt->fetch();
        }
        unset($row);
        return $rows;
    }

    public static function import(array $preview, int $actorId): array
    {
        if ((int)($preview['created_at'] ?? 0) < time() - 1800 || !isset($preview['rows']) || !is_array($preview['rows'])) {
            throw new DomainException('確認データの有効期限が切れました。CSVをもう一度選択してください。');
        }
        if ((int)($preview['error_count'] ?? 0) > 0) throw new DomainException('エラー行を修正してから、もう一度CSVを選択してください。');
        $pdo = Database::connection();
        $insert = $pdo->prepare('INSERT INTO leave_grants (employee_id, granted_on, grant_year, days, expires_on, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $findEmployee = $pdo->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE');
        $updateRenewalMonth = $pdo->prepare('UPDATE employees SET leave_renewal_month=?, updated_at=NOW() WHERE id=?');
        $findDuplicate = $pdo->prepare('SELECT id FROM leave_grants WHERE employee_id=? AND grant_year=? AND granted_on=? AND days=? AND expires_on=? LIMIT 1');
        $insertAdvance = $pdo->prepare('INSERT INTO leave_adjustments (employee_id, grant_year, days_delta, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $imported = 0; $skipped = 0;
        $pdo->beginTransaction();
        try {
            foreach ($preview['rows'] as $row) {
                $employeeId = (int)($row['employee_id'] ?? 0); $findEmployee->execute([$employeeId]);
                if (!$findEmployee->fetch()) throw new DomainException('取り込み中に対象社員が見つからなくなりました。');
                if (!empty($row['renewal_month'])) $updateRenewalMonth->execute([(int)$row['renewal_month'], $employeeId]);
                if (!empty($row['duplicate']) || (string)($row['skip_reason'] ?? '') !== '') { $skipped++; continue; }
                $findDuplicate->execute([$employeeId, (int)$row['grant_year'], $row['granted_on'], (float)$row['days'], $row['expires_on']]);
                if ($findDuplicate->fetch()) { $skipped++; continue; }
                $reason = 'CSV初期移行' . ($row['note'] !== '' ? '：' . $row['note'] : '');
                $insert->execute([$employeeId, $row['granted_on'], (int)$row['grant_year'], (float)$row['days'], $row['expires_on'], $reason, $actorId]);
                $advanceDays = (float)($row['advance_days'] ?? 0);
                if ($advanceDays > 0.0) {
                    $insertAdvance->execute([$employeeId, (int)$row['grant_year'], -$advanceDays, 'CSV初期移行：初回付与前の前借', $actorId]);
                }
                $imported++;
            }
            Audit::log('leave_import_completed', 'leave_grant_import', null, null, ['file_name' => $preview['source_name'] ?? '', 'file_sha256' => $preview['source_hash'] ?? '', 'source_format' => $preview['source_format'] ?? '', 'as_of' => $preview['as_of'] ?? null, 'imported' => $imported, 'skipped' => $skipped], $actorId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }
}
