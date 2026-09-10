<?php
declare(strict_types=1);

use App\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

$name = (string)($argv[1] ?? '');
if ($name === '' || basename($name) !== $name || !preg_match('/\A[0-9A-Za-z_-]+\.sql\z/', $name)) {
    fwrite(STDERR, "Usage: php scripts/run-migration.php <migration-file.sql>\n");
    exit(2);
}

$path = dirname(__DIR__) . '/database/migrations/' . $name;
if (!is_file($path)) {
    fwrite(STDERR, "Migration not found: {$name}\n");
    exit(2);
}

$sql = file_get_contents($path);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "Migration is empty: {$name}\n");
    exit(2);
}

Database::connection()->exec($sql);
fwrite(STDOUT, "Migration applied: {$name}\n");
