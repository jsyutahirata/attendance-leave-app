<?php
declare(strict_types=1);

namespace App;

/**
 * アプリ全体の設定（app_settings のキー・バリュー）へのアクセス。
 * リクエスト内でキャッシュする。
 */
final class Settings
{
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::connection()->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll() as $row) {
                self::$cache[(string)$row['setting_key']] = (string)$row['setting_value'];
            }
        }
        return self::$cache[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default ? '1' : '0');
        return $v === '1' || $v === 'true';
    }

    public static function set(string $key, string $value, ?int $actorId = null): void
    {
        Database::connection()->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()'
        )->execute([$key, $value, $actorId]);
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }
}
