<?php
declare(strict_types=1);

namespace App;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(self::token()) . '">';
    }

    public static function verify(): void
    {
        $token = (string)($_POST['csrf_token'] ?? '');
        if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
            http_response_code(419);
            exit('セッションが期限切れです。画面を再読み込みしてください。');
        }
    }
}

