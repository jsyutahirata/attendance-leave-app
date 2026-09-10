<?php
declare(strict_types=1);

namespace App;

use DomainException;

/**
 * 社内問い合わせ機能。内容はDBに保存せず、Chatworkの指定ルームへ通知する。
 *
 * 必要な環境変数（.env）:
 *   CHATWORK_API_TOKEN … Chatwork APIトークン
 *   CHATWORK_ROOM_ID   … 送信先ルームID（数字）
 */
final class ContactService
{
    private const ENDPOINT = 'https://api.chatwork.com/v2/rooms/%s/messages';

    /** 問い合わせ種別（表示ラベル）。 */
    public const CATEGORIES = [
        'trouble' => '不具合・エラー',
        'question' => '使い方の質問',
        'request' => '改善要望',
        'other' => 'その他',
    ];

    public static function configured(): bool
    {
        return (string)config('CHATWORK_API_TOKEN', '') !== '' && (string)config('CHATWORK_ROOM_ID', '') !== '';
    }

    /**
     * 問い合わせをChatworkへ送信する。
     * @param array $user Auth::user() の配列（full_name / email を利用）
     */
    public static function send(array $user, string $category, string $body): void
    {
        $body = trim($body);
        if ($body === '') {
            throw new DomainException('問い合わせ内容を入力してください。');
        }
        if (mb_strlen($body) > 2000) {
            throw new DomainException('問い合わせ内容が長すぎます。2000文字以内で入力してください。');
        }
        if (!isset(self::CATEGORIES[$category])) {
            $category = 'other';
        }
        if (!self::configured()) {
            error_log('Contact: Chatwork is not configured (CHATWORK_API_TOKEN / CHATWORK_ROOM_ID).');
            throw new DomainException('問い合わせ先が未設定です。管理担当者へ直接ご連絡ください。');
        }

        $name = (string)($user['full_name'] ?? '不明');
        $email = (string)($user['email'] ?? '');
        $message = "[info][title]社内勤怠管理 問い合わせ[/title]"
            . "差出人: {$name}" . ($email !== '' ? " ({$email})" : '') . "\n"
            . '種別: ' . self::CATEGORIES[$category] . "\n"
            . '受付: ' . date('Y-m-d H:i') . "\n"
            . "----------\n"
            . $body
            . '[/info]';

        self::post((string)config('CHATWORK_ROOM_ID', ''), (string)config('CHATWORK_API_TOKEN', ''), $message);
    }

    private static function post(string $roomId, string $token, string $message): void
    {
        $url = sprintf(self::ENDPOINT, rawurlencode($roomId));
        if (!function_exists('curl_init')) {
            throw new DomainException('サーバーが問い合わせ送信に対応していません。管理担当者へご連絡ください。');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new DomainException('問い合わせの送信を開始できませんでした。');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['body' => $message, 'self_unread' => '0']),
            CURLOPT_HTTPHEADER => ['X-ChatWorkToken: ' . $token],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'AttendanceLeaveApp/1.0',
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($response === false || $status < 200 || $status >= 300) {
            error_log('Contact: Chatwork API failed. HTTP ' . $status . ' / ' . $error . ' / ' . (is_string($response) ? $response : ''));
            throw new DomainException('問い合わせを送信できませんでした。時間をおいて再度お試しください。');
        }
    }
}
