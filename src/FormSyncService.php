<?php
declare(strict_types=1);

namespace App;

/**
 * 部分移行用: アプリのネイティブ打刻を、元Googleフォームの formResponse へ転送する。
 *
 * form_sync_enabled=1 の社員だけが対象。送信はベストエフォート（失敗しても打刻は成立させる）。
 * 送信先・項目IDは .env で切り替える（テストフォーム → 本番フォーム）。
 *   FORM_SYNC_ENDPOINT   … フォームの formResponse URL
 *   FORM_SYNC_ENTRY_NAME … 氏名項目の entry.<ID>
 *   FORM_SYNC_ENTRY_TYPE … 出退勤項目の entry.<ID>
 */
final class FormSyncService
{
    public static function configured(): bool
    {
        return (string)config('FORM_SYNC_ENDPOINT', '') !== ''
            && (string)config('FORM_SYNC_ENTRY_NAME', '') !== ''
            && (string)config('FORM_SYNC_ENTRY_TYPE', '') !== '';
    }

    /**
     * 対象社員の打刻をフォームへ転送する。対象外・未設定・未構成なら何もしない。
     * @param array<string,mixed> $employee form_sync_enabled / form_sync_name を含む社員行
     */
    public static function submitForEmployee(array $employee, string $eventType): void
    {
        if ((int)($employee['form_sync_enabled'] ?? 0) !== 1 || !self::configured()) {
            return;
        }
        $formName = trim((string)($employee['form_sync_name'] ?? ''));
        if ($formName === '') {
            error_log('[form-sync] form_sync_name 未設定のため送信をスキップ: employee_id=' . ($employee['id'] ?? '?'));
            return;
        }
        $typeLabel = $eventType === 'clock_in' ? '出勤' : ($eventType === 'clock_out' ? '退勤' : '');
        if ($typeLabel === '') {
            return;
        }
        try {
            $ok = self::post([
                (string)config('FORM_SYNC_ENTRY_NAME') => $formName,
                (string)config('FORM_SYNC_ENTRY_TYPE') => $typeLabel,
            ]);
            if (!$ok) {
                error_log('[form-sync] 送信失敗: name=' . $formName . ' type=' . $typeLabel);
            }
        } catch (\Throwable $e) {
            // 打刻自体は成立させる。転送の失敗はログのみ。
            error_log('[form-sync] 例外: ' . $e->getMessage());
        }
    }

    /** @param array<string,string> $fields */
    private static function post(array $fields): bool
    {
        $endpoint = (string)config('FORM_SYNC_ENDPOINT');
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            error_log('[form-sync] curl error: ' . $err);
            return false;
        }
        return $code >= 200 && $code < 400;
    }
}
