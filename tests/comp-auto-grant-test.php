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
final class Auth { public static function id(): int { return 1; } }
final class Audit { public static function log(...$args): void {} }
require dirname(__DIR__) . '/src/CompLeaveService.php';
$pdo = Database::$pdo = new TestPdo('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('NOW', static fn() => '2026-09-08 12:00:00');
$pdo->exec('CREATE TABLE employees (id INTEGER PRIMARY KEY); INSERT INTO employees VALUES (1),(2); CREATE TABLE comp_leave_grants (id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER, occurred_on TEXT, fiscal_year INTEGER, days REAL, expires_on TEXT, notice_id INTEGER, status TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT)');
function check(bool $ok, string $label): void {
    if (!$ok) throw new \RuntimeException($label);
    echo "PASS $label\n";
}
$pdo->beginTransaction();
check(CompLeaveService::generateForHolidayWork(1, '2026-09-12', null) > 0, 'Clock-in grants a day');
check(CompLeaveService::generateForHolidayWork(1, '2026-09-12', null) === 0, 'Repeated clock-in does not duplicate');
check(CompLeaveService::generateForHolidayWork(1, '2026-09-12', 10) === 0, 'Notice after clock-in does not duplicate');
check(CompLeaveService::generateForHolidayWork(1, '2026-09-13', 11) > 0, 'Separate day grants independently');
check(CompLeaveService::generateForHolidayWork(1, '2026-09-13', null) === 0, 'Clock-in after notice does not duplicate');
check(CompLeaveService::generateForHolidayWork(2, '2026-09-12', null) > 0, 'Separate employee grants independently');
$pdo->exec("UPDATE comp_leave_grants SET status='cancelled' WHERE employee_id=1 AND occurred_on='2026-09-12'");
check(CompLeaveService::generateForHolidayWork(1, '2026-09-12', null) === 0, 'Cancelled grant stays cancelled');
check(CompLeaveService::expiryFor('2026-09-12') === '2027-03-31', 'Existing fiscal expiry preserved');
$pdo->rollBack();
check((int)$pdo->query('SELECT COUNT(*) FROM comp_leave_grants')->fetchColumn() === 0, 'Transaction rollback removes grants');
