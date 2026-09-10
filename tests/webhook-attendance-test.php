<?php
declare(strict_types=1);
namespace App;
// WebhookAttendanceService の業務ルールを SQLite で隔離検証する（MySQL の行ロックは別途確認）。
final class TestPdo extends \PDO {
    public function prepare(string $query, array $options = []): \PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
final class Database {
    public static \PDO $pdo;
    public static function connection(): \PDO { return self::$pdo; }
}
final class Auth { public static function id(): int { return 1; } }
final class Audit { public static function log(...$args): void {} }
require dirname(__DIR__) . '/src/WebhookException.php';
require dirname(__DIR__) . '/src/CompLeaveService.php';
require dirname(__DIR__) . '/src/WebhookAttendanceService.php';

$pdo = Database::$pdo = new TestPdo('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn() => (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
$pdo->exec(
    'CREATE TABLE employees (id INTEGER PRIMARY KEY, full_name TEXT);'
    . "INSERT INTO employees VALUES (1,'平田雄大'),(2,'鈴木 尚人'),(3,'退職者');"
    . 'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, status TEXT);'
    . "INSERT INTO users (employee_id,status) VALUES (1,'active'),(2,'active'),(3,'disabled');"
    . 'CREATE TABLE attendance_events (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, event_type TEXT, occurred_at TEXT, created_by INTEGER, created_at TEXT);'
    . 'CREATE TABLE form_webhook_events (id INTEGER PRIMARY KEY AUTOINCREMENT, response_id TEXT UNIQUE, attendance_event_id INTEGER, received_at TEXT);'
    . 'CREATE TABLE comp_leave_grants (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, occurred_on TEXT, fiscal_year INTEGER, days REAL, expires_on TEXT, notice_id INTEGER, status TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT)'
);

function check(bool $ok, string $label): void {
    if (!$ok) throw new \RuntimeException("FAIL: $label");
    echo "PASS $label\n";
}
/** @return string 実行された WebhookException のエラーコード */
function expectError(callable $fn): string {
    try { $fn(); } catch (WebhookException $e) { return $e->getErrorCode(); }
    return '(no exception)';
}
// 過去の直近で指定曜日(N)の日付を返す（範囲チェックと曜日を確実に満たすため）。
function recentDow(string $n): string {
    $d = new \DateTimeImmutable('today');
    for ($i = 1; $i <= 10; $i++) {
        $c = $d->modify("-$i day");
        if ($c->format('N') === $n) return $c->format('Y-m-d');
    }
    throw new \RuntimeException('no date');
}
$weekday  = recentDow('2'); // 火曜
$saturday = recentDow('6'); // 土曜

// 1. 正常な出勤→退勤（平日）。
$r = WebhookAttendanceService::record(['name' => '平田雄大', 'event_type' => 'clock_in', 'occurred_at' => "$weekday 09:00:00"]);
check($r['duplicate'] === false && $r['employee_id'] === 1 && $r['comp_granted'] === false, '平日の出勤を記録');
check((int)$pdo->query("SELECT created_by FROM attendance_events WHERE id={$r['event_id']}")->fetchColumn() === 1, 'created_by に本人ユーザーIDが入る');
WebhookAttendanceService::record(['name' => '平田雄大', 'event_type' => 'clock_out', 'occurred_at' => "$weekday 18:00:00"]);
check((int)$pdo->query("SELECT COUNT(*) FROM attendance_events WHERE employee_id=1")->fetchColumn() === 2, '退勤も記録され2件');

// 2. 順序違反：退勤済みの直後にまた退勤。
check(expectError(fn() => WebhookAttendanceService::record(['name' => '平田雄大', 'event_type' => 'clock_out', 'occurred_at' => "$weekday 19:00:00"])) === 'out_of_order', '順序違反は out_of_order');
check((int)$pdo->query("SELECT COUNT(*) FROM attendance_events WHERE employee_id=1")->fetchColumn() === 2, '順序違反時は記録しない');

// 3. 氏名の空白ゆれを正規化して照合（DBは「鈴木 尚人」、入力は「鈴木尚人」）。
$r = WebhookAttendanceService::record(['name' => '鈴木尚人', 'event_type' => 'clock_in', 'occurred_at' => "$weekday 09:00:00"]);
check($r['employee_id'] === 2, '氏名の空白ゆれを吸収して照合');

// 4. 該当社員なし。
check(expectError(fn() => WebhookAttendanceService::record(['name' => '平田テスト', 'event_type' => 'clock_in', 'occurred_at' => "$weekday 09:00:00"])) === 'unknown_employee', '未登録氏名は unknown_employee');

// 5. 有効ユーザーなし（退職者）。
check(expectError(fn() => WebhookAttendanceService::record(['name' => '退職者', 'event_type' => 'clock_in', 'occurred_at' => "$weekday 09:00:00"])) === 'no_user_for_employee', '無効ユーザーは no_user_for_employee');

// 6. 冪等：同一 response_id の二重送信は1件だけ。
$before = (int)$pdo->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn();
$a = WebhookAttendanceService::record(['name' => '平田雄大', 'event_type' => 'clock_in', 'occurred_at' => "$weekday 20:00:00", 'response_id' => 'RESP-1']);
$b = WebhookAttendanceService::record(['name' => '平田雄大', 'event_type' => 'clock_out', 'occurred_at' => "$weekday 21:00:00", 'response_id' => 'RESP-1']);
check($a['duplicate'] === false && $b['duplicate'] === true, '同一response_idは2回目をduplicate扱い');
check((int)$pdo->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn() === $before + 1, '二重送信でも記録は1件');

// 7. 不正な event_type / occurred_at。
check(expectError(fn() => WebhookAttendanceService::record(['name' => '平田雄大', 'event_type' => 'lunch', 'occurred_at' => "$weekday 09:00:00"])) === 'invalid_payload', '不正なevent_typeは invalid_payload');
check(expectError(fn() => WebhookAttendanceService::record(['name' => '平田雄大', 'event_type' => 'clock_in', 'occurred_at' => '2026/09/09'])) === 'invalid_payload', '不正な日時形式は invalid_payload');

// 8. 土曜の出勤は代休1日を自動付与。
$pdo->exec('DELETE FROM attendance_events WHERE employee_id=1');
$r = WebhookAttendanceService::record(['name' => '平田雄大', 'event_type' => 'clock_in', 'occurred_at' => "$saturday 10:00:00"]);
check($r['comp_granted'] === true, '土曜出勤で代休を自動付与');
check((int)$pdo->query("SELECT COUNT(*) FROM comp_leave_grants WHERE employee_id=1 AND status='active'")->fetchColumn() === 1, '代休付与が1件記録される');

echo "\nAll webhook attendance tests passed.\n";
