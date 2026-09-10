<?php
declare(strict_types=1);

namespace App;

// Isolated in-memory database; never connects to application data.
final class Database
{
    public static \PDO $pdo;
    public static function connection(): \PDO { return self::$pdo; }
}
final class Auth
{
    public static ?array $user = ['role' => 'employee', 'employee_id' => 1];
    public static function user(): ?array { return self::$user; }
}
require dirname(__DIR__) . '/src/Access.php';
$pdo = Database::$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('CURDATE', static fn() => '2026-09-08');
$pdo->exec('CREATE TABLE employees (id INTEGER); CREATE TABLE users (employee_id INTEGER); CREATE TABLE group_memberships (group_id INTEGER, employee_id INTEGER); CREATE TABLE view_grants (viewer_type TEXT, viewer_employee_id INTEGER, viewer_group_id INTEGER, target_type TEXT, target_employee_id INTEGER, target_group_id INTEGER, expires_on TEXT)');
$pdo->exec('INSERT INTO employees VALUES (1),(2); INSERT INTO users VALUES (1),(2)');
function check(bool $ok, string $label): void {
    if (!$ok) { throw new \RuntimeException($label); }
    echo "PASS $label\n";
}
check(Access::canView(1) && !Access::canView(2), 'Self only without grant');
$pdo->exec("INSERT INTO view_grants VALUES ('employee',1,NULL,'all',NULL,NULL,NULL)");
check(Access::canView(2), 'All grant permits reading another employee');
check(!Access::canManage(2), 'All grant does not permit editing another employee');
try { Access::assertManage(2); throw new \RuntimeException('Edit unexpectedly allowed'); }
catch (\DomainException $e) { echo "PASS Edit assertion rejects viewer\n"; }
$pdo->exec('INSERT INTO employees VALUES (3); INSERT INTO users VALUES (3)');
check(Access::canView(3) && count(Access::viewableEmployeeIds(1)) === 2, 'New employees are included automatically');
$pdo->exec("UPDATE view_grants SET expires_on='2026-09-07'");
check(!Access::canView(2) && Access::viewableEmployeeIds(1) === [], 'Expired grant is excluded from detail and list');
$pdo->exec("UPDATE view_grants SET expires_on='2026-09-08'");
check(Access::canView(2), 'Grant valid through expiry day');
$pdo->exec('DELETE FROM view_grants');
check(!Access::canView(2), 'Revocation takes effect');
Auth::$user = ['role' => 'admin', 'employee_id' => 1];
check(Access::canView(2) && Access::canManage(2), 'Admin access preserved');
Auth::$user = null;
check(!Access::canView(2) && !Access::canManage(2), 'Anonymous access denied');
