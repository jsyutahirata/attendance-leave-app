<?php
declare(strict_types=1);

namespace App;

use DomainException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class PushService
{
    public static function publicKey(): string
    {
        return trim((string)config('VAPID_PUBLIC_KEY', ''));
    }

    public static function configured(): bool
    {
        return self::publicKey() !== '' && trim((string)config('VAPID_PRIVATE_KEY', '')) !== '';
    }

    public static function saveSubscription(int $userId, array $data): void
    {
        $endpoint = trim((string)($data['endpoint'] ?? ''));
        $keys = is_array($data['keys'] ?? null) ? $data['keys'] : [];
        $publicKey = trim((string)($keys['p256dh'] ?? ''));
        $authToken = trim((string)($keys['auth'] ?? ''));
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !str_starts_with($endpoint, 'https://') || $publicKey === '' || $authToken === '') {
            throw new DomainException('Push購読情報が正しくありません。');
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, public_key, auth_token, content_encoding, user_agent, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), endpoint=VALUES(endpoint), public_key=VALUES(public_key), auth_token=VALUES(auth_token), content_encoding=VALUES(content_encoding), user_agent=VALUES(user_agent), updated_at=NOW()'
        );
        $stmt->execute([$userId, $endpoint, hash('sha256', $endpoint), $publicKey, $authToken, 'aes128gcm', mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)]);
    }

    public static function removeSubscription(int $userId, string $endpoint): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?');
        $stmt->execute([$userId, hash('sha256', $endpoint)]);
    }

    public static function subscriptionCount(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** @return array{enabled:bool,reminder_time:string} */
    public static function preference(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT enabled, TIME_FORMAT(reminder_time, \'%H:%i\') AS reminder_time FROM push_preferences WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return [
            'enabled' => !$row || (int)$row['enabled'] === 1,
            'reminder_time' => $row ? (string)$row['reminder_time'] : (string)config('PUSH_REMINDER_TIME', '18:00'),
        ];
    }

    public static function savePreference(int $userId, bool $enabled, string $reminderTime): void
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $reminderTime)) {
            throw new DomainException('通知時刻を正しく入力してください。');
        }
        Database::connection()->prepare(
            'INSERT INTO push_preferences (user_id, enabled, reminder_time, updated_at) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), reminder_time=VALUES(reminder_time), updated_at=NOW()'
        )->execute([$userId, $enabled ? 1 : 0, $reminderTime . ':00']);
    }

    /** @return array{users:int,sent:int,failed:int,expired:int} */
    public static function sendClockOutReminders(): array
    {
        if (!self::configured()) {
            throw new DomainException('VAPID_PUBLIC_KEY と VAPID_PRIVATE_KEY を設定してください。');
        }
        $defaultTime = (string)config('PUSH_REMINDER_TIME', '18:00');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $defaultTime)) $defaultTime = '18:00';

        $pdo = Database::connection();
        $sql = "SELECT u.id AS user_id, ps.id AS subscription_id, ps.endpoint, ps.public_key, ps.auth_token, ps.content_encoding
                FROM users u
                JOIN push_subscriptions ps ON ps.user_id=u.id
                LEFT JOIN push_preferences pp ON pp.user_id=u.id
                JOIN attendance_events last_event ON last_event.id=(SELECT ae.id FROM attendance_events ae WHERE ae.employee_id=u.employee_id ORDER BY ae.occurred_at DESC, ae.id DESC LIMIT 1)
                WHERE u.status='active' AND last_event.event_type='clock_in'
                  AND COALESCE(pp.enabled, 1)=1
                  AND COALESCE(pp.reminder_time, ?) <= CURTIME()
                  AND NOT EXISTS (SELECT 1 FROM leave_entries le WHERE le.employee_id=u.employee_id AND le.leave_date=CURDATE() AND le.status IN ('registered','approved','taken'))
                  AND NOT EXISTS (SELECT 1 FROM push_notification_logs pnl WHERE pnl.user_id=u.id AND pnl.notification_type='clock_out_reminder' AND pnl.work_date=CURDATE())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$defaultTime . ':00']);
        $rows = $stmt->fetchAll();
        if (!$rows) return ['users' => 0, 'sent' => 0, 'failed' => 0, 'expired' => 0];

        // 送信前にユーザー単位で当日分を確保し、Cron重複起動やElectronフォールバックとの二重通知を防ぐ。
        $claim = $pdo->prepare("INSERT IGNORE INTO push_notification_logs (user_id, notification_type, work_date, sent_at) VALUES (?, 'clock_out_reminder', CURDATE(), NOW())");
        $claimedUsers = [];
        foreach ($rows as $row) {
            $userId = (int)$row['user_id'];
            if (array_key_exists($userId, $claimedUsers)) continue;
            $claim->execute([$userId]);
            $claimedUsers[$userId] = $claim->rowCount() === 1;
        }
        $rows = array_values(array_filter($rows, static fn(array $row): bool => $claimedUsers[(int)$row['user_id']] ?? false));
        if (!$rows) return ['users' => 0, 'sent' => 0, 'failed' => 0, 'expired' => 0];

        $auth = ['VAPID' => [
            'subject' => (string)config('VAPID_SUBJECT', (string)config('APP_URL')),
            'publicKey' => self::publicKey(),
            'privateKey' => trim((string)config('VAPID_PRIVATE_KEY', '')),
        ]];
        $webPush = new WebPush($auth);
        $payload = json_encode([
            'title' => '退勤打刻の確認',
            'body' => '出勤中です。退勤済みの場合は打刻を確認してください。',
            'url' => '/index.php?route=attendance',
            'tag' => 'clock-out-' . date('Y-m-d'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($rows as $row) {
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $row['endpoint'], 'publicKey' => $row['public_key'],
                'authToken' => $row['auth_token'], 'contentEncoding' => $row['content_encoding'],
            ]), $payload, ['TTL' => 3600, 'urgency' => 'normal']);
        }

        $sentUsers = [];
        $stats = ['users' => 0, 'sent' => 0, 'failed' => 0, 'expired' => 0];
        foreach ($webPush->flush() as $report) {
            $row = null;
            foreach ($rows as $candidate) {
                if (hash_equals((string)$candidate['endpoint'], $report->getEndpoint())) { $row = $candidate; break; }
            }
            if (!$row) continue;
            if ($report->isSuccess()) {
                $stats['sent']++;
                $sentUsers[(int)$row['user_id']] = true;
            } else {
                $stats['failed']++;
                if ($report->isSubscriptionExpired()) {
                    $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$row['subscription_id']]);
                    $stats['expired']++;
                }
            }
        }
        $release = $pdo->prepare("DELETE FROM push_notification_logs WHERE user_id=? AND notification_type='clock_out_reminder' AND work_date=CURDATE()");
        foreach ($claimedUsers as $userId => $claimed) {
            if ($claimed && !isset($sentUsers[$userId])) $release->execute([$userId]);
        }
        $stats['users'] = count($sentUsers);
        return $stats;
    }

    public static function claimElectronReminder(int $userId): ?array
    {
        $preference = self::preference($userId);
        if (!$preference['enabled'] || date('H:i') < $preference['reminder_time']) return null;
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT u.employee_id FROM users u
          JOIN attendance_events ae ON ae.id=(SELECT x.id FROM attendance_events x WHERE x.employee_id=u.employee_id ORDER BY x.occurred_at DESC,x.id DESC LIMIT 1)
          WHERE u.id=? AND u.status='active' AND ae.event_type='clock_in'
            AND NOT EXISTS (SELECT 1 FROM leave_entries le WHERE le.employee_id=u.employee_id AND le.leave_date=CURDATE() AND le.status IN ('registered','approved','taken'))");
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) return null;
        $insert = $pdo->prepare("INSERT IGNORE INTO push_notification_logs (user_id, notification_type, work_date, sent_at) VALUES (?, 'clock_out_reminder', CURDATE(), NOW())");
        $insert->execute([$userId]);
        if ($insert->rowCount() !== 1) return null;
        return ['title' => '退勤打刻の確認', 'body' => '出勤中です。退勤済みの場合は打刻を確認してください。'];
    }
}
