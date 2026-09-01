<?php
declare(strict_types=1);

namespace App;

use DomainException;

/**
 * 所有者・閲覧権限照合の共通ロジック（仕様書 §14）。
 *
 * 本人データを扱う全APIは、リクエストされた社員IDに対する権限をここで照合する。
 * エンドポイントごとに個別実装せず、必ずこのクラスを経由すること。
 *
 * - 参照(view): 本人 / 管理者 / 有効な閲覧権限(view_grants)を持つ者
 * - 更新(manage): 本人 / 管理者 のみ。閲覧権限(view_grants)経由では一切許可しない。
 *
 * 閲覧権限(view_grants)は後続フェーズ(B2)で導入する。canView() 内のフックを
 * 実装するだけで参照系の判定に組み込めるよう、判定入口をここに一本化しておく。
 */
final class Access
{
    /** 参照権限: 本人・管理者・閲覧権限保持者のいずれか。 */
    public static function canView(int $employeeId): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        if ($user['role'] === 'admin') {
            return true;
        }
        if ((int)$user['employee_id'] === $employeeId) {
            return true;
        }
        return self::hasViewGrant((int)$user['employee_id'], $employeeId);
    }

    /** 更新権限: 本人または管理者のみ（閲覧権限では不可）。 */
    public static function canManage(int $employeeId): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        return $user['role'] === 'admin' || (int)$user['employee_id'] === $employeeId;
    }

    /** 参照権限がなければ403で停止する。 */
    public static function assertView(int $employeeId): void
    {
        if (!self::canView($employeeId)) {
            self::deny();
        }
    }

    /** 更新権限がなければ拒否する（業務例外）。 */
    public static function assertManage(int $employeeId): void
    {
        if (!self::canManage($employeeId)) {
            throw new DomainException('この操作を行う権限がありません。');
        }
    }

    /**
     * 閲覧権限(view_grants)の有無。B2で view_grants / group_memberships を参照して実装する。
     * 現時点では常に false（本人・管理者以外は参照不可）。
     */
    private static function hasViewGrant(int $viewerEmployeeId, int $targetEmployeeId): bool
    {
        return false;
    }

    private static function deny(): never
    {
        http_response_code(403);
        render('error', ['title' => 'アクセスできません', 'message' => 'このデータを参照する権限がありません。']);
        exit;
    }
}
