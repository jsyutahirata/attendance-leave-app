<?php
declare(strict_types=1);

// Googleフォーム（Apps Script）からの打刻を受け取る専用エントリ。
// 既存 index.php の CSRF / ログイン強制を通れないため、独立エントリとして共有トークンで認証する。

// ローカルは public/ と src/ が兄弟。XServer本番は公開/非公開が別ツリー（index.php と同じ解決）。
$bootstrap = dirname(__DIR__) . '/src/bootstrap.php';
if (!is_file($bootstrap)) {
    $bootstrap = dirname(__DIR__, 2) . '/attendance_app/src/bootstrap.php';
}
require $bootstrap;

use App\WebhookAttendanceService;
use App\WebhookException;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ① 共有トークン検証（タイミング安全比較）。
$expected = (string)config('FORM_WEBHOOK_TOKEN', '');
$provided = (string)($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');
if ($expected === '' || !hash_equals($expected, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ② 入力パース。
try {
    $payload = json_decode((string)file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ③ 記録処理。
try {
    $result = WebhookAttendanceService::record($payload);
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
} catch (WebhookException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getErrorCode(), 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[webhook] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal'], JSON_UNESCAPED_UNICODE);
}
