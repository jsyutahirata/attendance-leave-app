<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Database;

$pdo = Database::connection();
$taken = $pdo->exec("UPDATE leave_entries SET status = 'taken', updated_at = NOW() WHERE status IN ('registered','approved') AND leave_date < CURDATE()");
$compTaken = $pdo->exec("UPDATE comp_leave_entries SET status = 'taken', updated_at = NOW() WHERE status = 'registered' AND leave_date < CURDATE()");
$deletedTokens = $pdo->exec('DELETE FROM password_reset_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY) OR used_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
$deletedRememberTokens = $pdo->exec('DELETE FROM remember_login_tokens WHERE expires_at < NOW()');
fwrite(STDOUT, sprintf("Maintenance completed: %d leave and %d comp-leave statuses updated, %d reset tokens and %d remember tokens deleted.\n", $taken, $compTaken, $deletedTokens, $deletedRememberTokens));
