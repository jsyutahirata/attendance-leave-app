<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

$options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
$windowsConfig = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
if (PHP_OS_FAMILY === 'Windows' && is_file($windowsConfig)) $options['config'] = $windowsConfig;
$key = openssl_pkey_new($options);
$details = $key === false ? false : openssl_pkey_get_details($key);
if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
    throw new RuntimeException('OpenSSLでP-256鍵を生成できませんでした。openssl拡張と設定を確認してください。');
}
$encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$public = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
$private = str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);
fwrite(STDOUT, 'VAPID_PUBLIC_KEY=' . $encode($public) . PHP_EOL . 'VAPID_PRIVATE_KEY=' . $encode($private) . PHP_EOL);
