<?php
declare(strict_types=1);

namespace App;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use OTPHP\TOTP as OtpTotp;

/**
 * TOTP（二要素認証）ユーティリティ（仕様書 §5）。
 * TOTP検証は spomky-labs/otphp、QRコード(SVG)は bacon/bacon-qr-code を用いる。
 * SVG生成はGD拡張を必要としない（XServerでGDが無効でも動作する）。
 */
final class Totp
{
    private const ISSUER = '社内勤怠管理';

    /** 新しいシークレット（Base32）を生成する。 */
    public static function newSecret(): string
    {
        return OtpTotp::generate()->getSecret();
    }

    /** 認証アプリ登録用の otpauth:// URI。ラベルにはメールアドレス等を渡す。 */
    public static function provisioningUri(string $secret, string $label): string
    {
        $totp = OtpTotp::createFromSecret($secret);
        $totp->setLabel($label);
        $totp->setIssuer(self::ISSUER);
        return $totp->getProvisioningUri();
    }

    /** otpauth URI をQRコードのSVG文字列に変換する。 */
    public static function qrSvg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd()));
        return $writer->writeString($uri);
    }

    /** 入力コードがシークレットに対して正しいか検証する。 */
    public static function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        return OtpTotp::createFromSecret($secret)->verify($code);
    }

    /** バックアップコードを生成する（表示用の平文、xxxx-xxxx形式）。 */
    public static function generateBackupCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $n = str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $codes[] = substr($n, 0, 4) . '-' . substr($n, 4);
        }
        return $codes;
    }

    /** バックアップコードのハッシュ（数字のみで正規化してからSHA-256）。 */
    public static function hashBackup(string $code): string
    {
        return hash('sha256', preg_replace('/\D/', '', $code) ?? '');
    }
}
