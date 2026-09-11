<?php
declare(strict_types=1);

// 指定メールアドレスのアカウント（users＋employees）と、それに紐づく業務データを完全に削除する運用スクリプト。
// テスト用に作ったアカウントの後始末などに使う。操作履歴(audit_logs)は actor を NULL 化して履歴自体は残す。
//
// 使い方:
//   php scripts/delete-account.php you@example.com            … ドライラン（何が消えるか表示のみ）
//   php scripts/delete-account.php you@example.com --confirm  … 実削除
//
// 本番データを削除するため、まず必ずドライランで確認してから --confirm すること。

use App\Database;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

$confirm = in_array('--confirm', $argv, true);
$email = '';
foreach (array_slice($argv, 1) as $a) { if ($a !== '--confirm') { $email = mb_strtolower(trim($a)); break; } }
if ($email === '') { fwrite(STDERR, "Usage: php scripts/delete-account.php <email> [--confirm]\n"); exit(2); }

$pdo = Database::connection();
$stmt = $pdo->prepare('SELECT u.id AS user_id, u.employee_id, u.email, u.secondary_email, e.full_name FROM users u JOIN employees e ON e.id = u.employee_id WHERE u.email = ? OR u.secondary_email = ? LIMIT 1');
$stmt->execute([$email, $email]);
$acct = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$acct) { fwrite(STDOUT, "該当アカウントが見つかりません: {$email}\n"); exit(0); }

$userId = (int)$acct['user_id'];
$employeeId = (int)$acct['employee_id'];
fwrite(STDOUT, "=== 対象アカウント ===\n");
fwrite(STDOUT, "  user_id={$userId} / employee_id={$employeeId} / 氏名={$acct['full_name']} / email={$acct['email']}" . ($acct['secondary_email'] ? " / sub={$acct['secondary_email']}" : '') . "\n");

$tableExists = static function (\PDO $pdo, string $t): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $s->execute([$t]); return (int)$s->fetchColumn() > 0;
};

// [ラベル, テーブル, WHERE, バインド]
$ops = [
    ['フォーム連携冪等記録', 'form_webhook_events', 'attendance_event_id IN (SELECT id FROM attendance_events WHERE employee_id = ?)', [$employeeId]],
    ['打刻',               'attendance_events',   'employee_id = ?', [$employeeId]],
    ['勤怠連絡',           'attendance_notices',  'employee_id = ?', [$employeeId]],
    ['有給予定',           'leave_entries',       'employee_id = ?', [$employeeId]],
    ['有給付与',           'leave_grants',        'employee_id = ?', [$employeeId]],
    ['有給調整',           'leave_adjustments',   'employee_id = ?', [$employeeId]],
    ['代休付与',           'comp_leave_grants',   'employee_id = ?', [$employeeId]],
    ['代休予定',           'comp_leave_entries',  'employee_id = ?', [$employeeId]],
    ['グループ所属',       'group_memberships',   'employee_id = ?', [$employeeId]],
    ['閲覧権限',           'view_grants',         'viewer_employee_id = ? OR target_employee_id = ?', [$employeeId, $employeeId]],
    ['プッシュ購読',       'push_subscriptions',  'user_id = ?', [$userId]],
    ['プッシュ設定',       'push_preferences',    'user_id = ?', [$userId]],
    ['プッシュ通知履歴',   'push_notification_logs', 'user_id = ?', [$userId]],
    ['TOTPバックアップ',   'totp_backup_codes',   'user_id = ?', [$userId]],
    ['再設定トークン',     'password_reset_tokens', 'user_id = ?', [$userId]],
    ['ログイン保持',       'remember_login_tokens', 'user_id = ?', [$userId]],
];

fwrite(STDOUT, "\n=== " . ($confirm ? '削除実行' : 'ドライラン（削除件数の見積り）') . " ===\n");

if (!$confirm) {
    $auditN = 0;
    if ($tableExists($pdo, 'audit_logs')) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = ?'); $s->execute([$userId]); $auditN = (int)$s->fetchColumn();
    }
    foreach ($ops as [$label, $table, $where, $binds]) {
        if (!$tableExists($pdo, $table)) { fwrite(STDOUT, sprintf("  %-22s %6s   (%s: なし)\n", $label, '-', $table)); continue; }
        $c = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $where"); $c->execute($binds);
        fwrite(STDOUT, sprintf("  %-22s %6d 件\n", $label, (int)$c->fetchColumn()));
    }
    fwrite(STDOUT, sprintf("  %-22s %6d 件（actorをNULL化して履歴は残す）\n", '操作履歴(この人の操作)', $auditN));
    fwrite(STDOUT, "  " . str_repeat('-', 40) . "\n  users 1件 + employees 1件 を削除\n");
    fwrite(STDOUT, "\nドライランです。実削除するには --confirm を付けてください。\n");
    exit(0);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->beginTransaction();
try {
    foreach ($ops as [$label, $table, $where, $binds]) {
        if (!$tableExists($pdo, $table)) continue;
        $pdo->prepare("DELETE FROM $table WHERE $where")->execute($binds);
    }
    if ($tableExists($pdo, 'audit_logs')) {
        $pdo->prepare('UPDATE audit_logs SET actor_user_id = NULL WHERE actor_user_id = ?')->execute([$userId]);
    }
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM employees WHERE id = ?')->execute([$employeeId]);
    \App\Audit::log('account_deleted', 'user', $userId, ['email' => $acct['email'], 'full_name' => $acct['full_name']], null, null);
    $pdo->commit();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    fwrite(STDOUT, "アカウント（{$acct['full_name']} / {$acct['email']}）を削除しました。\n");
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    fwrite(STDERR, "削除に失敗しました。ロールバックしました: " . $e->getMessage() . "\n");
    exit(1);
}
