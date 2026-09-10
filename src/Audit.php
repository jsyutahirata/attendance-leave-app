<?php
declare(strict_types=1);

namespace App;

final class Audit
{
    public static function log(string $action, string $targetType, ?int $targetId = null, mixed $before = null, mixed $after = null, ?int $actorId = null): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, before_json, after_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $actorId ?? Auth::id(),
            $action,
            $targetType,
            $targetId,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
