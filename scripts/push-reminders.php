<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

use App\PushService;

try {
    $stats = PushService::sendClockOutReminders();
    fwrite(STDOUT, sprintf("Push completed: %d users, %d sent, %d failed, %d expired subscriptions removed.\n", $stats['users'], $stats['sent'], $stats['failed'], $stats['expired']));
} catch (Throwable $e) {
    fwrite(STDERR, "Push failed: {$e->getMessage()}\n");
    exit(1);
}
