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
     * 閲覧権限(view_grants)の有無を判定する（§4.3, §14）。
     * viewer・target はそれぞれ個人指定またはグループ所属で一致すればよい。期限切れは除外する。
     * ※ DBはEMULATE_PREPARES=falseのため同名プレースホルダを再利用できない。位置パラメータで渡す。
     */
    private static function hasViewGrant(int $viewerEmployeeId, int $targetEmployeeId): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM view_grants vg
             WHERE (vg.expires_on IS NULL OR vg.expires_on >= CURDATE())
               AND (
                 (vg.viewer_type = 'employee' AND vg.viewer_employee_id = ?)
                 OR (vg.viewer_type = 'group' AND EXISTS (SELECT 1 FROM group_memberships gmv WHERE gmv.group_id = vg.viewer_group_id AND gmv.employee_id = ?))
               )
               AND (
                 (vg.target_type = 'employee' AND vg.target_employee_id = ?)
                 OR (vg.target_type = 'group' AND EXISTS (SELECT 1 FROM group_memberships gmt WHERE gmt.group_id = vg.target_group_id AND gmt.employee_id = ?))
               )
             LIMIT 1"
        );
        $stmt->execute([$viewerEmployeeId, $viewerEmployeeId, $targetEmployeeId, $targetEmployeeId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * 指定した閲覧者が閲覧できる対象社員IDの一覧（自分自身は除く）。閲覧専用ページの一覧に使う。
     * 管理者は全社員を閲覧できるため、この関数ではなく呼び出し側で全社員を対象とする。
     */
    public static function viewableEmployeeIds(int $viewerEmployeeId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT vg.target_type, vg.target_employee_id, vg.target_group_id FROM view_grants vg
             WHERE (vg.expires_on IS NULL OR vg.expires_on >= CURDATE())
               AND (
                 (vg.viewer_type = 'employee' AND vg.viewer_employee_id = ?)
                 OR (vg.viewer_type = 'group' AND EXISTS (SELECT 1 FROM group_memberships gmv WHERE gmv.group_id = vg.viewer_group_id AND gmv.employee_id = ?))
               )"
        );
        $stmt->execute([$viewerEmployeeId, $viewerEmployeeId]);
        $ids = [];
        $groupIds = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['target_type'] === 'employee' && $row['target_employee_id'] !== null) {
                $ids[(int)$row['target_employee_id']] = true;
            } elseif ($row['target_type'] === 'group' && $row['target_group_id'] !== null) {
                $groupIds[(int)$row['target_group_id']] = true;
            }
        }
        if ($groupIds) {
            $in = implode(',', array_fill(0, count($groupIds), '?'));
            $stmt = $pdo->prepare("SELECT DISTINCT employee_id FROM group_memberships WHERE group_id IN ($in)");
            $stmt->execute(array_keys($groupIds));
            foreach ($stmt->fetchAll() as $row) {
                $ids[(int)$row['employee_id']] = true;
            }
        }
        unset($ids[$viewerEmployeeId]); // 自分自身は除外
        return array_keys($ids);
    }

    private static function deny(): never
    {
        http_response_code(403);
        render('error', ['title' => 'アクセスできません', 'message' => 'このデータを参照する権限がありません。']);
        exit;
    }
}
