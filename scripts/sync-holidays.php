<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\HolidayService;

try {
    $result = HolidayService::sync();
    fwrite(STDOUT, sprintf("Holiday sync completed: %d records, latest %s.\n", $result['count'], $result['latest']));
} catch (Throwable $e) {
    fwrite(STDERR, 'Holiday sync failed: ' . $e->getMessage() . "\n");
    exit(1);
}
