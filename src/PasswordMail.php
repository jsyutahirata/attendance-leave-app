<?php
declare(strict_types=1);
namespace App;

final class PasswordMail
{
    /** パスワード再設定メールを送信する。 */
    public static function send(string $email, string $link): bool
    {
        $body = "以下のURLから60分以内にパスワードを再設定してください。\n\n" . $link
            . "\n\nこのリンクは1回限り有効です。\n心当たりがない場合は、このメールを破棄してください。パスワードは変更されません。\n";
        return self::dispatch($email, 'パスワード再設定', $body);
    }

    /** アカウント作成時の招待（初回パスワード設定）メールを送信する。 */
    public static function sendInvite(string $email, string $link): bool
    {
        $body = "社内勤怠管理のアカウントが作成されました。\n\n"
            . "以下のURLから24時間以内にパスワードを設定してください。\n\n" . $link
            . "\n\nこのリンクは1回限り有効です。\n心当たりがない場合は、このメールを破棄してください。\n";
        return self::dispatch($email, '社内勤怠管理アカウントのご案内', $body);
    }

    /** 送信元・宛先を検証し、UTF-8（Base64）でエンコードして送信する。 */
    private static function dispatch(string $email, string $subject, string $body): bool
    {
        $from = (string)config('MAIL_FROM', '');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || preg_match('/[\r\n]/', $from . $email)) {
            error_log('Mail: invalid sender or recipient configuration');
            return false;
        }
        $name = str_replace(["\r", "\n"], '', (string)config('MAIL_FROM_NAME', '社内勤怠管理'));
        $headers = [
            'From' => mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n") . ' <' . $from . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
        ];
        return mail($email, mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n"), chunk_split(base64_encode($body)), $headers);
    }
}
