<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Controller;
use App\Csrf;

try {
    $controller = new Controller();
    $route = trim((string)($_GET['route'] ?? ''), '/');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        Csrf::verify();
    }

    $publicRoutes = ['login', 'login/totp', 'forgot-password', 'reset-password'];
    if (!in_array($route, $publicRoutes, true)) {
        Auth::requireLogin();
    }

    $controller->dispatch($route, $method);
} catch (Throwable $e) {
    if (config('APP_DEBUG', 'false') === 'true') {
        http_response_code(500);
        echo '<pre>' . e($e->__toString()) . '</pre>';
    } else {
        error_log($e->__toString());
        http_response_code(500);
        render('error', ['title' => 'エラー', 'message' => '処理中にエラーが発生しました。管理担当者へご連絡ください。']);
    }
}

