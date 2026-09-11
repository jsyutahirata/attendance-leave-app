<?php
declare(strict_types=1);

// 指定した除外社員（既定: 平田テスト）を除く全社員の業務データを削除する運用スクリプト。
// アカウント本体（users / employees）と認証系（パスワード・2FA・セッション・監査ログ）は残す。
//
// 使い方:
//   php scripts/reset-employee-data.php                 … ドライラン（何が何件消えるか表示のみ）
//   php scripts/reset-employee-data.php --confirm       … 実削除
//   php scripts/reset-employee-data.php --except="氏名"  … 除外する社員名を変更（既定: 平田テスト）
//
// 本番データを削除するため、まず必ずドライランで件数を確認してから --confirm すること。

use App\Database;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

$confirm = in_array('--confirm', $argv, true);
$except = '平田テスト';
foreach ($argv as $a) {
    if (str_starts_with($a, '--except=')) { $except = substr($a, strlen('--except=')); }
}

$pdo = Database::connection();

$tableExists = static function (\PDO $pdo, string $table): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $s->execute([$table]);
    return (int)$s->fetchColumn() > 0;
};

// 対象社員（除外名以外）を確定。
$stmt = $pdo->prepare('SELECT id, full_name FROM employees WHERE full_name <> ? ORDER BY id');
$stmt->execute([$except]);
$targets = $stmt->fetchAll(\PDO::FETCH_ASSOC);
$empIds = array_map(static fn($r) => (int)$r['id'], $targets);

$kept = $pdo->prepare('SELECT id, full_name FROM employees WHERE full_name = ? ORDER BY id');
$kept->execute([$except]);
$keptRows = $kept->fetchAll(\PDO::FETCH_ASSOC);

fwrite(STDOUT, "=== 除外（残す）社員: full_name = '{$except}' ===\n");
foreach ($keptRows as $r) { fwrite(STDOUT, "  KEEP  #{$r['id']} {$r['full_name']}\n"); }
if (!$keptRows) { fwrite(STDOUT, "  （該当なし。除外名を確認してください）\n"); }

fwrite(STDOUT, "\n=== 削除対象社員（" . count($empIds) . "名）===\n");
foreach ($targets as $r) { fwrite(STDOUT, "  WIPE  #{$r['id']} {$r['full_name']}\n"); }

if (!$empIds) {
    fwrite(STDOUT, "\n削除対象がいません。終了します。\n");
    exit(0);
}

$ph = implode(',', array_fill(0, count($empIds), '?'));
$userStmt = $pdo->prepare("SELECT id FROM users WHERE employee_id IN ($ph)");
$userStmt->execute($empIds);
$userIds = array_map('intval', $userStmt->fetchAll(\PDO::FETCH_COLUMN));
$uph = $userIds ? implode(',', array_fill(0, count($userIds), '?')) : 'NULL';

// [ラベル, テーブル, WHERE, バインド値] の順で、社員/ユーザースコープの削除対象を定義。
$ops = [
    ['フォーム連携冪等記録', 'form_webhook_events', "attendance_event_id IN (SELECT id FROM attendance_events WHERE employee_id IN ($ph))", $empIds],
    ['打刻',               'attendance_events',   "employee_id IN ($ph)", $empIds],
    ['勤怠連絡',           'attendance_notices',  "employee_id IN ($ph)", $empIds],
    ['有給予定',           'leave_entries',       "employee_id IN ($ph)", $empIds],
    ['有給付与',           'leave_grants',        "employee_id IN ($ph)", $empIds],
    ['有給調整',           'leave_adjustments',   "employee_id IN ($ph)", $empIds],
    ['代休付与',           'comp_leave_grants',   "employee_id IN ($ph)", $empIds],
    ['代休予定',           'comp_leave_entries',  "employee_id IN ($ph)", $empIds],
    ['グループ所属',       'group_memberships',   "employee_id IN ($ph)", $empIds],
    ['閲覧権限',           'view_grants',         "viewer_employee_id IN ($ph) OR target_employee_id IN ($ph)", array_merge($empIds, $empIds)],
    ['プッシュ購読',       'push_subscriptions',  "user_id IN ($uph)", $userIds],
    ['プッシュ設定',       'push_preferences',    "user_id IN ($uph)", $userIds],
    ['プッシュ通知履歴',   'push_notification_logs', "user_id IN ($uph)", $userIds],
];

fwrite(STDOUT, "\n=== " . ($confirm ? '削除実行' : 'ドライラン（削除件数の見積り）') . " ===\n");

if (!$confirm) {
    $total = 0;
    foreach ($ops as [$label, $table, $where, $binds]) {
        if (!$tableExists($pdo, $table)) { fwrite(STDOUT, sprintf("  %-22s %6s      (%s: テーブルなし・スキップ)\n", $label, '-', $table)); continue; }
        if ($table === 'push_subscriptions' || $table === 'push_preferences' || $table === 'push_notification_logs') {
            if (!$userIds) { fwrite(STDOUT, sprintf("  %-22s %6s 件  (%s)\n", $label, 0, $table)); continue; }
        }
        $c = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $where");
        $c->execute($binds);
        $n = (int)$c->fetchColumn();
        $total += $n;
        fwrite(STDOUT, sprintf("  %-22s %6d 件  (%s)\n", $label, $n, $table));
    }
    fwrite(STDOUT, "  " . str_repeat('-', 40) . "\n");
    fwrite(STDOUT, sprintf("  %-22s %6d 件\n", '合計', $total));
    fwrite(STDOUT, "\nドライランです。実削除するには --confirm を付けて実行してください。\n");
    exit(0);
}

// --- 実削除 ---
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->beginTransaction();
try {
    $deleted = [];
    foreach ($ops as [$label, $table, $where, $binds]) {
        if (!$tableExists($pdo, $table)) { $deleted[$label] = 0; continue; }
        if (($table === 'push_subscriptions' || $table === 'push_preferences' || $table === 'push_notification_logs') && !$userIds) {
            $deleted[$label] = 0; continue;
        }
        $d = $pdo->prepare("DELETE FROM $table WHERE $where");
        $d->execute($binds);
        $deleted[$label] = $d->rowCount();
    }
    \App\Audit::log('employee_data_reset', 'employees', null, null, ['except' => $except, 'targets' => count($empIds), 'deleted' => $deleted], null);
    $pdo->commit();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $total = 0;
    foreach ($deleted as $label => $n) { $total += $n; fwrite(STDOUT, sprintf("  削除 %-22s %6d 件\n", $label, $n)); }
    fwrite(STDOUT, "  " . str_repeat('-', 40) . "\n");
    fwrite(STDOUT, sprintf("  合計 %27d 件\n", $total));
    fwrite(STDOUT, "\n完了しました。\n");
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    fwrite(STDERR, "削除に失敗しました。ロールバックしました: " . $e->getMessage() . "\n");
    exit(1);
}
