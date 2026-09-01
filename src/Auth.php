<?php
declare(strict_types=1);

namespace App;

final class Auth
{
    private const REMEMBER_COOKIE = 'attendance_remember';
    private static ?array $user = null;
    private static bool $rememberChecked = false;

    public static function attempt(string $email, string $password, bool $remember = false): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT u.*, e.full_name FROM users u JOIN employees e ON e.id = u.employee_id WHERE u.email = ? LIMIT 1');
        $stmt->execute([mb_strtolower(trim($email))]);
        $user = $stmt->fetch();
        $now = new \DateTimeImmutable();

        if (!$user || $user['status'] !== 'active' || ($user['locked_until'] && new \DateTimeImmutable($user['locked_until']) > $now) || !password_verify($password, $user['password_hash'])) {
            if ($user) {
                $attempts = (int)$user['failed_login_attempts'] + 1;
                $max = (int)config('LOGIN_MAX_ATTEMPTS', 5);
                $lockedUntil = $attempts >= $max ? $now->modify('+' . (int)config('LOGIN_LOCK_MINUTES', 15) . ' minutes')->format('Y-m-d H:i:s') : null;
                $pdo->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?')->execute([$attempts, $lockedUntil, $user['id']]);
            }
            Audit::log('login_failed', 'user', $user ? (int)$user['id'] : null, null, ['email' => $email], $user ? (int)$user['id'] : null);
            return false;
        }

        $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        // 現在の失効エポックをセッションへ複製する。無効化・パスワード変更・強制ログアウトで
        // users.session_token がローテーションされると、複製済みの本値と不一致になり失効する。
        $_SESSION['session_token'] = (string)$user['session_token'];
        $_SESSION['last_activity'] = time();
        self::$user = null;
        self::revokeRememberCookie();
        if ($remember && $user['role'] !== 'admin') {
            self::issueRememberToken((int)$user['id']);
        }
        Audit::log('login_success', 'user', (int)$user['id'], null, null, (int)$user['id']);
        return true;
    }

    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            self::resumeRememberedLogin();
            if (empty($_SESSION['user_id'])) {
                return null;
            }
        }
        if (self::$user === null) {
            $stmt = Database::connection()->prepare("SELECT u.*, e.full_name, e.employee_code FROM users u JOIN employees e ON e.id = u.employee_id WHERE u.id = ? AND u.status = 'active'");
            $stmt->execute([$_SESSION['user_id']]);
            $row = $stmt->fetch() ?: null;
            // 失効エポック照合。session_token が一致しなければ、この端末セッションは失効済み。
            if ($row && !hash_equals((string)$row['session_token'], (string)($_SESSION['session_token'] ?? ''))) {
                $row = null;
            }
            if ($row === null) {
                unset($_SESSION['user_id'], $_SESSION['session_token'], $_SESSION['last_activity']);
            }
            self::$user = $row;
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return self::user() ? (int)self::user()['id'] : null;
    }

    public static function employeeId(): ?int
    {
        return self::user() ? (int)self::user()['employee_id'] : null;
    }

    public static function requireLogin(): void
    {
        $idle = (int)config('SESSION_IDLE_MINUTES', 60) * 60;
        if (!empty($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $idle) {
            unset($_SESSION['user_id'], $_SESSION['last_activity']);
            session_regenerate_id(true);
            self::$user = null;
            self::$rememberChecked = false;
        }
        if (!self::user()) {
            redirect('login');
        }
        $_SESSION['last_activity'] = time();
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (self::user()['role'] !== 'admin') {
            http_response_code(403);
            render('error', ['title' => 'アクセスできません', 'message' => '管理者権限が必要です。']);
            exit;
        }
    }

    public static function logout(bool $audit = true): void
    {
        if ($audit && self::id()) {
            Audit::log('logout', 'user', self::id());
        }
        self::revokeRememberCookie();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        self::$user = null;
    }

    /**
     * 対象ユーザーの全端末セッション・長期ログイントークンを一括失効させる（仕様書 §5, §14）。
     * users.session_token をローテーションすることで、各端末が複製済みの旧トークンと不一致になり、
     * 次回リクエスト時にセッションが無効化される。無効化・パスワード変更・強制ログアウトで使用する。
     * 呼び出し側の既存トランザクション内で実行してよい。
     */
    public static function revokeAllSessions(int $userId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE users SET session_token = ?, updated_at = NOW() WHERE id = ?')
            ->execute([bin2hex(random_bytes(16)), $userId]);
        $pdo->prepare('DELETE FROM remember_login_tokens WHERE user_id = ?')->execute([$userId]);
    }

    private static function issueRememberToken(int $userId): void
    {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $days = max(1, (int)config('REMEMBER_LOGIN_DAYS', 30));
        $expiresAt = (new \DateTimeImmutable())->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
        Database::connection()->prepare(
            'INSERT INTO remember_login_tokens (user_id, selector, validator_hash, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())'
        )->execute([$userId, $selector, hash('sha256', $validator), $expiresAt]);
        self::setRememberCookie($selector . ':' . $validator, time() + ($days * 86400));
    }

    private static function resumeRememberedLogin(): void
    {
        if (self::$rememberChecked) {
            return;
        }
        self::$rememberChecked = true;
        $cookie = (string)($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if (!preg_match('/^([a-f0-9]{32}):([a-f0-9]{64})$/', $cookie, $matches)) {
            if ($cookie !== '') self::setRememberCookie('', time() - 3600);
            return;
        }
        [, $selector, $validator] = $matches;
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT rt.*, u.status, u.role, u.session_token FROM remember_login_tokens rt JOIN users u ON u.id = rt.user_id WHERE rt.selector = ? AND rt.expires_at >= NOW() LIMIT 1");
        $stmt->execute([$selector]);
        $token = $stmt->fetch();
        if (!$token || $token['status'] !== 'active' || !hash_equals((string)$token['validator_hash'], hash('sha256', $validator))) {
            $pdo->prepare('DELETE FROM remember_login_tokens WHERE selector = ?')->execute([$selector]);
            self::setRememberCookie('', time() - 3600);
            return;
        }

        $newValidator = bin2hex(random_bytes(32));
        $days = max(1, (int)config('REMEMBER_LOGIN_DAYS', 30));
        $expiresAt = (new \DateTimeImmutable())->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
        $pdo->prepare('UPDATE remember_login_tokens SET validator_hash = ?, expires_at = ?, last_used_at = NOW() WHERE id = ?')
            ->execute([hash('sha256', $newValidator), $expiresAt, $token['id']]);
        self::setRememberCookie($selector . ':' . $newValidator, time() + ($days * 86400));
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$token['user_id'];
        $_SESSION['session_token'] = (string)$token['session_token'];
        $_SESSION['last_activity'] = time();
        self::$user = null;
        Audit::log('login_remembered', 'user', (int)$token['user_id'], null, null, (int)$token['user_id']);
    }

    private static function revokeRememberCookie(): void
    {
        $cookie = (string)($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if (preg_match('/^([a-f0-9]{32}):/', $cookie, $matches)) {
            Database::connection()->prepare('DELETE FROM remember_login_tokens WHERE selector = ?')->execute([$matches[1]]);
        }
        self::setRememberCookie('', time() - 3600);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    private static function setRememberCookie(string $value, int $expires): void
    {
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => config('APP_ENV', 'production') === 'production' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
