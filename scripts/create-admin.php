<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;

[$script, $email, $name, $password, $employeeCode] = array_pad($argv, 5, '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || trim($name) === '' || mb_strlen($password) < 12) {
    fwrite(STDERR, "Usage: php scripts/create-admin.php email name password [employee-code]\nPassword must contain at least 12 characters.\n");
    exit(1);
}

$pdo = Database::connection();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO employees (employee_code, full_name, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
    $stmt->execute([$employeeCode !== '' ? $employeeCode : null, trim($name)]);
    $employeeId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO users (employee_id, email, password_hash, role, status, session_token, failed_login_attempts, created_at, updated_at) VALUES (?, ?, ?, 'admin', 'active', ?, 0, NOW(), NOW())");
    $stmt->execute([$employeeId, mb_strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT), bin2hex(random_bytes(16))]);
    $pdo->commit();
    fwrite(STDOUT, "Administrator created.\n");
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Could not create administrator: " . $e->getMessage() . "\n");
    exit(1);
}
