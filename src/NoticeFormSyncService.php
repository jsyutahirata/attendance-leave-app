<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/**
 * 部分移行: アプリで登録した勤怠連絡（遅刻・早退・有給・欠勤・休日出勤・健康診断・その他）を、
 * 元の「勤怠連絡フォーム」の formResponse へ転送する。
 *
 * notice_sync_enabled=1 の社員だけが対象。氏名は form_sync_name を共用。送信はベストエフォート。
 * 送信先・項目IDは .env で切り替える:
 *   FORM_NOTICE_SYNC_ENDPOINT    … フォームの formResponse URL
 *   FORM_NOTICE_SYNC_ENTRY_NAME  … 氏名 entry.<ID>
 *   FORM_NOTICE_SYNC_ENTRY_TYPE  … 勤怠種別 entry.<ID>
 *   FORM_NOTICE_SYNC_ENTRY_DATE  … 取得予定日 entry.<ID>（_year/_month/_day で送る）
 *   FORM_NOTICE_SYNC_ENTRY_START … 出勤見込み時刻 entry.<ID>（_hour/_minute）
 *   FORM_NOTICE_SYNC_ENTRY_END   … 退勤見込み時刻 entry.<ID>（_hour/_minute）
 *   FORM_NOTICE_SYNC_ENTRY_REASON… 申請理由 entry.<ID>
 */
final class NoticeFormSyncService
{
    /** アプリの notice_type → フォームの勤怠種別ラベル。 */
    private const TYPE_LABELS = [
        'late' => '遅刻',
        'early' => '早退',
        'leave_full' => '有給休暇（1日）',
        'leave_am' => '有給休暇（午前休）',
        'leave_pm' => '有給休暇（午後休）',
        'absence' => '欠勤',
        'holiday_work' => '休日出勤',
        'medical' => '健康診断',
        'other' => 'その他',
    ];

    public static function configured(): bool
    {
        foreach (['FORM_NOTICE_SYNC_ENDPOINT', 'FORM_NOTICE_SYNC_ENTRY_NAME', 'FORM_NOTICE_SYNC_ENTRY_TYPE', 'FORM_NOTICE_SYNC_ENTRY_DATE', 'FORM_NOTICE_SYNC_ENTRY_REASON'] as $key) {
            if ((string)config($key, '') === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $employee notice_sync_enabled / form_sync_name を含む社員行
     * @param array<string,mixed> $notice   notice_type / target_date / expected_start / expected_end / details
     * @return 'ok'|'failed'|'skipped'
     */
    public static function submitForEmployee(array $employee, array $notice): string
    {
        if ((int)($employee['notice_sync_enabled'] ?? 0) !== 1 || !self::configured()) {
            return 'skipped';
        }
        $formName = trim((string)($employee['form_sync_name'] ?? ''));
        if ($formName === '') {
            error_log('[notice-sync] form_sync_name 未設定のため送信をスキップ: employee_id=' . ($employee['id'] ?? '?'));
            return 'failed';
        }
        $typeLabel = self::TYPE_LABELS[(string)($notice['notice_type'] ?? '')] ?? '';
        if ($typeLabel === '') {
            return 'skipped';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($notice['target_date'] ?? ''));
        if (!$date) {
            return 'skipped';
        }

        $typeEntry = (string)config('FORM_NOTICE_SYNC_ENTRY_TYPE');
        $dateEntry = (string)config('FORM_NOTICE_SYNC_ENTRY_DATE');
        $fields = [
            (string)config('FORM_NOTICE_SYNC_ENTRY_NAME') => $formName,
            $dateEntry . '_year' => $date->format('Y'),
            $dateEntry . '_month' => $date->format('n'),
            $dateEntry . '_day' => $date->format('j'),
            (string)config('FORM_NOTICE_SYNC_ENTRY_REASON') => (string)($notice['details'] ?? ''),
        ];
        // 種別。'other' はラジオの「その他」なので __other_option__ ＋ 自由記述で送る。
        if ((string)($notice['notice_type'] ?? '') === 'other') {
            $fields[$typeEntry] = '__other_option__';
            $fields[$typeEntry . '.other_option_response'] = (string)($notice['details'] ?? '') !== '' ? (string)$notice['details'] : 'その他';
        } else {
            $fields[$typeEntry] = $typeLabel;
        }

        self::addTime($fields, (string)config('FORM_NOTICE_SYNC_ENTRY_START', ''), (string)($notice['expected_start'] ?? ''));
        self::addTime($fields, (string)config('FORM_NOTICE_SYNC_ENTRY_END', ''), (string)($notice['expected_end'] ?? ''));

        try {
            $ok = self::post($fields);
            if (!$ok) {
                error_log('[notice-sync] 送信失敗: name=' . $formName . ' type=' . $typeLabel);
            }
            return $ok ? 'ok' : 'failed';
        } catch (\Throwable $e) {
            error_log('[notice-sync] 例外: ' . $e->getMessage());
            return 'failed';
        }
    }

    /** 時刻（H:i / H:i:s）を _hour/_minute として追加。空・未設定・entry未設定なら何もしない。 */
    private static function addTime(array &$fields, string $entry, string $time): void
    {
        $time = trim($time);
        if ($entry === '' || $time === '') {
            return;
        }
        $parts = explode(':', $time);
        if (count($parts) < 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return;
        }
        $fields[$entry . '_hour'] = (string)(int)$parts[0];
        $fields[$entry . '_minute'] = (string)(int)$parts[1];
    }

    /** @param array<string,string> $fields */
    private static function post(array $fields): bool
    {
        $ch = curl_init((string)config('FORM_NOTICE_SYNC_ENDPOINT'));
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
            error_log('[notice-sync] curl error: ' . $err);
            return false;
        }
        return $code >= 200 && $code < 400;
    }
}
