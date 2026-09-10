<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/**
 * Googleフォーム（出退勤フォーム）からの打刻を attendance_events に記録するサービス。
 *
 * 既存の Controller::clock() と同じ整合性ルール（順序ガード・行ロック・土日代休の自動付与）を踏襲する。
 * ログインセッションを持たないため、created_by には「打刻した本人の有効ユーザーID」を用いる。
 */
final class WebhookAttendanceService
{
    /**
     * @param array<string,mixed> $payload name / event_type / occurred_at / response_id
     * @return array<string,mixed> 記録結果（duplicate / event_id / employee_id / comp_granted）
     */
    public static function record(array $payload): array
    {
        $eventType = (string)($payload['event_type'] ?? '');
        if (!in_array($eventType, ['clock_in', 'clock_out'], true)) {
            throw new WebhookException('invalid_payload', 'event_type が不正です。');
        }

        $occurredAt = self::parseOccurredAt((string)($payload['occurred_at'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new WebhookException('invalid_payload', '氏名が空です。');
        }
        $responseId = trim((string)($payload['response_id'] ?? ''));

        $pdo = Database::connection();

        // 冪等: 同一フォーム回答IDが既に処理済みなら二重記録しない。
        if ($responseId !== '' && self::alreadyProcessed($pdo, $responseId)) {
            return ['duplicate' => true];
        }

        $employeeId = self::resolveEmployeeId($pdo, $name);
        $userId = self::resolveActorUserId($pdo, $employeeId);

        $pdo->beginTransaction();
        try {
            // 同一社員の打刻を直列化する。
            $lock = $pdo->prepare('SELECT id FROM employees WHERE id = ? FOR UPDATE');
            $lock->execute([$employeeId]);

            $stmt = $pdo->prepare('SELECT event_type FROM attendance_events WHERE employee_id = ? ORDER BY occurred_at DESC, id DESC LIMIT 1 FOR UPDATE');
            $stmt->execute([$employeeId]);
            $last = $stmt->fetch();
            $expected = (!$last || $last['event_type'] === 'clock_out') ? 'clock_in' : 'clock_out';
            if ($eventType !== $expected) {
                throw new WebhookException(
                    'out_of_order',
                    $eventType === 'clock_in' ? 'すでに出勤中です（退勤打刻が先に必要です）。' : '先に出勤打刻が必要です。'
                );
            }

            $ins = $pdo->prepare('INSERT INTO attendance_events (employee_id, event_type, occurred_at, created_by, created_at) VALUES (?, ?, ?, ?, NOW())');
            $ins->execute([$employeeId, $eventType, $occurredAt->format('Y-m-d H:i:s'), $userId]);
            $eventId = (int)$pdo->lastInsertId();

            if ($responseId !== '') {
                $dedupe = $pdo->prepare('INSERT INTO form_webhook_events (response_id, attendance_event_id, received_at) VALUES (?, ?, NOW())');
                $dedupe->execute([$responseId, $eventId]);
            }

            Audit::log($eventType, 'attendance_event', $eventId, null, [
                'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
                'source' => 'google_form',
            ], $userId);

            // 土日出勤は代休1日を自動付与（既存 clock() と同挙動）。
            $grantId = 0;
            if ($eventType === 'clock_in' && (int)$occurredAt->format('N') >= 6) {
                $grantId = CompLeaveService::generateForHolidayWork($employeeId, $occurredAt->format('Y-m-d'), null);
            }

            $pdo->commit();
            return [
                'duplicate' => false,
                'event_id' => $eventId,
                'employee_id' => $employeeId,
                'comp_granted' => $grantId > 0,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // 冪等テーブルのUNIQUE違反は「並行した二重送信」とみなし成功扱いにする。
            if ($responseId !== '' && !($e instanceof WebhookException) && self::alreadyProcessed($pdo, $responseId)) {
                return ['duplicate' => true];
            }
            throw $e;
        }
    }

    private static function parseOccurredAt(string $value): DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        if (!$dt || $dt->format('Y-m-d H:i:s') !== $value) {
            throw new WebhookException('invalid_payload', 'occurred_at の形式が不正です（Y-m-d H:i:s）。');
        }
        $now = new DateTimeImmutable();
        if ($dt > $now->modify('+1 day') || $dt < new DateTimeImmutable('2020-01-01 00:00:00')) {
            throw new WebhookException('invalid_payload', 'occurred_at が妥当な範囲外です。');
        }
        return $dt;
    }

    /** 氏名を employees.full_name と照合する（空白の有無ゆれは正規化して吸収）。 */
    private static function resolveEmployeeId(\PDO $pdo, string $name): int
    {
        $normalized = str_replace([' ', '　'], '', $name);
        $stmt = $pdo->prepare("SELECT id FROM employees WHERE REPLACE(REPLACE(full_name, ' ', ''), '　', '') = ?");
        $stmt->execute([$normalized]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (count($ids) === 0) {
            throw new WebhookException('unknown_employee', "氏名「{$name}」に一致する社員が見つかりません。");
        }
        if (count($ids) > 1) {
            throw new WebhookException('ambiguous_employee', "氏名「{$name}」に一致する社員が複数います。");
        }
        return (int)$ids[0];
    }

    /** 打刻者の有効ユーザーID（created_by 用）を取得する。 */
    private static function resolveActorUserId(\PDO $pdo, int $employeeId): int
    {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ? AND status = 'active' ORDER BY id LIMIT 1");
        $stmt->execute([$employeeId]);
        $userId = $stmt->fetchColumn();
        if ($userId === false) {
            throw new WebhookException('no_user_for_employee', '対象社員に有効なユーザーが存在しません。');
        }
        return (int)$userId;
    }

    private static function alreadyProcessed(\PDO $pdo, string $responseId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM form_webhook_events WHERE response_id = ? LIMIT 1');
        $stmt->execute([$responseId]);
        return $stmt->fetchColumn() !== false;
    }
}
