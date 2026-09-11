<?php
declare(strict_types=1);
namespace App;
// SQLite adapter for isolated business-rule tests; MySQL row locks are inspected separately.
final class TestPdo extends \PDO {
    public function prepare(string $query, array $options = []): \PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
final class Database {
    public static \PDO $pdo;
    public static function connection(): \PDO { return self::$pdo; }
}
final class Auth { public static function id(): ?int { return 1; } }
final class Audit { public static function log(...$args): void {} }
final class Settings { public static ?string $migration = null; public static function get(string $key, ?string $default = null): ?string { return $key === 'leave_migration_date' ? self::$migration : $default; } }
require dirname(__DIR__) . '/src/LeaveAccrualService.php';

$pdo = Database::$pdo = new TestPdo('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn() => '2026-09-08 12:00:00');
$pdo->exec('CREATE TABLE employees (id INTEGER PRIMARY KEY, hired_on TEXT, leave_renewal_month INTEGER)');
$pdo->exec("INSERT INTO employees VALUES (1,'2024-04-01',4),(2,'2020-04-01',4),(3,NULL,NULL),(4,'2024-04-01',NULL),(5,'2020-04-01',4)");
$pdo->exec('CREATE TABLE leave_grants (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, granted_on TEXT, grant_year INTEGER, days REAL, expires_on TEXT, reason TEXT, source TEXT, created_by INTEGER, created_at TEXT)');

function check(bool $ok, string $label): void {
    if (!$ok) throw new \RuntimeException($label);
    echo "PASS $label\n";
}

// --- 法定日数テーブル ---
check(LeaveAccrualService::daysForGrantNumber(1) === 10.0, '1回目=10日');
check(LeaveAccrualService::daysForGrantNumber(3) === 12.0, '3回目=12日');
check(LeaveAccrualService::daysForGrantNumber(4) === 14.0, '4回目=14日');
check(LeaveAccrualService::daysForGrantNumber(7) === 20.0, '7回目=20日');
check(LeaveAccrualService::daysForGrantNumber(12) === 20.0, '7回目以降は20日で頭打ち');

// --- 付与予定日の算出（入社6か月後以降の最初の更新月）---
$asOf = new \DateTimeImmutable('2026-09-08');
$grants = LeaveAccrualService::eligibleGrants(new \DateTimeImmutable('2024-04-01'), 4, $asOf);
check(count($grants) === 2, '2024-04入社/4月更新→2件付与予定');
check($grants[0]['granted_on'] === '2025-04-01' && $grants[0]['days'] === 10.0, '初回は2025-04-01の10日');
check($grants[1]['granted_on'] === '2026-04-01' && $grants[1]['days'] === 11.0, '2回目は2026-04-01の11日');

// 入社直後（6か月未満）は付与なし
check(LeaveAccrualService::eligibleGrants(new \DateTimeImmutable('2026-06-01'), 4, $asOf) === [], '6か月未満は付与なし');

// 移行基準日: 基準日より前の付与は除外。ただし付与回数（日数）は入社日基準を維持する。
$cut = LeaveAccrualService::eligibleGrants(new \DateTimeImmutable('2020-04-01'), 4, $asOf, new \DateTimeImmutable('2026-01-01'));
check(count($cut) === 1, '移行基準日以降の付与のみ（2020入社→2026分のみ）');
check($cut[0]['granted_on'] === '2026-04-01' && $cut[0]['number'] === 6 && $cut[0]['days'] === 18.0, '基準日後でも回数は入社基準（6回目=18日）');

// --- accrueForEmployee: 実付与と冪等性 ---
$asOfDate = new \DateTimeImmutable('2026-09-08');
check(LeaveAccrualService::accrueForEmployee(1, $asOfDate) === 2, '社員1に2件付与');
check(LeaveAccrualService::accrueForEmployee(1, $asOfDate) === 0, '再実行しても二重付与しない（冪等）');
$rows = $pdo->query("SELECT granted_on, grant_year, days, source, created_by FROM leave_grants WHERE employee_id=1 ORDER BY granted_on")->fetchAll(\PDO::FETCH_ASSOC);
check($rows[0]['source'] === 'auto_hire' && $rows[0]['created_by'] === null, 'source=auto_hire / created_by=NULL');
check((int)$rows[0]['grant_year'] === 2025 && (float)$rows[1]['days'] === 11.0, '付与年度と日数が正しい');
$expiry = $pdo->query("SELECT expires_on FROM leave_grants WHERE employee_id=1 AND granted_on='2025-04-01'")->fetchColumn();
check($expiry === '2027-03-31', '有効期限は付与日の2年後の前日');

// 勤続が長い社員は7回目以降20日で頭打ち（2020-04入社→2021..2026の6回、最新は18日）
check(LeaveAccrualService::accrueForEmployee(2, $asOfDate) === 6, '社員2は6件付与');
$latest = $pdo->query("SELECT days FROM leave_grants WHERE employee_id=2 ORDER BY granted_on DESC LIMIT 1")->fetchColumn();
check((float)$latest === 18.0, '2026年付与は6回目=18日');

// 全社共通の移行基準日を設定すると、その日以降ぶんのみ自動付与（過去はCSV実残数が正）
Settings::$migration = '2026-01-01';
check(LeaveAccrualService::accrueForEmployee(5, $asOfDate) === 1, '移行基準日設定時、社員5は基準日以降の1件のみ付与');
$e5 = $pdo->query("SELECT granted_on, days FROM leave_grants WHERE employee_id=5")->fetchAll(\PDO::FETCH_ASSOC);
check(count($e5) === 1 && $e5[0]['granted_on'] === '2026-04-01' && (float)$e5[0]['days'] === 18.0, '社員5の付与は2026-04-01の18日（6回目）のみ');
Settings::$migration = null;

// 入社日または更新月が未設定なら対象外
check(LeaveAccrualService::accrueForEmployee(3, $asOfDate) === 0, 'hired_on/更新月なしは対象外');
check(LeaveAccrualService::accrueForEmployee(4, $asOfDate) === 0, '更新月なしは対象外');

echo "All leave accrual tests passed.\n";
