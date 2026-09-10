<?php
declare(strict_types=1);
namespace App;
// Capture mail locally; this test never sends email.
$settings = ['MAIL_FROM' => 'reset@example.com', 'MAIL_FROM_NAME' => '社内勤怠管理'];
function config($key, $default = '') { global $settings; return $settings[$key] ?? $default; }
function mail($to, $subject, $body, $headers): bool {
    $GLOBALS['captured'] = compact('to', 'subject', 'body', 'headers');
    return true;
}
require dirname(__DIR__) . '/src/PasswordMail.php';
function check(bool $ok, string $label): void {
    if (!$ok) throw new \RuntimeException($label);
    echo "PASS $label\n";
}
check(PasswordMail::send('user@example.com', 'https://example.com/reset?token=test'), 'Mail submitted');
$mail = $GLOBALS['captured'];
check(mb_decode_mimeheader($mail['subject']) === 'パスワード再設定', 'Japanese subject');
check(str_contains(base64_decode($mail['body']), '60分以内') && str_contains(base64_decode($mail['body']), 'token=test'), 'Encoded body and reset link');
check($mail['headers']['Content-Type'] === 'text/plain; charset=UTF-8', 'Explicit encoding');
$settings['MAIL_FROM'] = "reset@example.com\r\nBcc: other@example.com";
check(!PasswordMail::send('user@example.com', 'https://example.com'), 'Invalid sender rejected');
