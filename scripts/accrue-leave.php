<?php
declare(strict_types=1);

// 入社月による有給の自動付与を全社員に対して実行する（冪等）。
// cron 例（毎日 02:10 実行）:
//   10 2 * * * /usr/bin/php /path/to/scripts/accrue-leave.php >> /path/to/logs/accrue-leave.log 2>&1

require dirname(__DIR__) . '/src/bootstrap.php';

use App\LeaveAccrualService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $result = LeaveAccrualService::accrueAll();
    fwrite(STDOUT, sprintf(
        "[%s] Leave accrual completed: %d employees checked, %d grants created.\n",
        date('Y-m-d H:i:s'),
        $result['employees'],
        $result['granted']
    ));
} catch (Throwable $e) {
    fwrite(STDERR, 'Leave accrual failed: ' . $e->getMessage() . "\n");
    exit(1);
}
