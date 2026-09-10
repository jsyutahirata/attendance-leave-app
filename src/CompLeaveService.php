<?php
declare(strict_types=1);

namespace App;

use DomainException;

/**
 * 代休（休日出勤に応じて発生する休暇）の管理（仕様書 §7.8）。
 * 有給とは発生条件・有効期限が異なるため、テーブル・残数計算を有給から分離する。
 *
 * - 発生: 勤怠連絡「休日出勤」の登録時に同一トランザクション内で1日分を自動発生させる。
 * - 有効期限: 発生日が属する事業年度（4月始まり）の年度末（翌年3/31）。
 * - 消化: 有効期限が近い付与から先に充当する。
 * - 初期版は1日単位のみ（半日代休は未確定・§18）。
 */
final class CompLeaveService
{
    /** 発生日が属する事業年度（4月始まり）。1〜3月は前年の年度に属する。 */
    public static function fiscalYear(string $date): int
    {
        $year = (int)substr($date, 0, 4);
        $month = (int)substr($date, 5, 2);
        return $month >= 4 ? $year : $year - 1;
    }

    /** 有効期限 = 事業年度末（翌年3/31）。 */
    public static function expiryFor(string $date): string
    {
        return (self::fiscalYear($date) + 1) . '-03-31';
    }

    /**
     * 代休残数のサマリー。
     * current   … 発生済み・未失効・未消化の残（取得済みはFIFOで差引済み）
     * scheduled … 取得予定（registered、未取得）
     * available … あと何日取得予約できるか（current - scheduled）
     */
    public static function summary(int $employeeId): array
    {
        $pdo = Database::connection();
        $today = date('Y-m-d');

        $stmt = $pdo->prepare("SELECT id, occurred_on, expires_on, days FROM comp_leave_grants WHERE employee_id = ? AND status = 'active' ORDER BY expires_on, id");
        $stmt->execute([$employeeId]);
        $grants = array_map(static fn(array $g): array => $g + ['remaining' => (float)$g['days']], $stmt->fetchAll());

        // 取得済み（過去）を有効期限の近い付与から充当する。
        $stmt = $pdo->prepare("SELECT leave_date, days FROM comp_leave_entries WHERE employee_id = ? AND status = 'taken' ORDER BY leave_date, id");
        $stmt->execute([$employeeId]);
        foreach ($stmt->fetchAll() as $entry) {
            $toConsume = (float)$entry['days'];
            foreach ($grants as &$grant) {
                if ($toConsume <= 0.0) break;
                if ($grant['occurred_on'] > $entry['leave_date'] || $grant['expires_on'] < $entry['leave_date'] || $grant['remaining'] <= 0.0) continue;
                $amount = min($toConsume, $grant['remaining']);
                $grant['remaining'] -= $amount;
                $toConsume -= $amount;
            }
            unset($grant);
        }

        $current = 0.0;
        foreach ($grants as $grant) {
            if ($grant['expires_on'] >= $today) {
                $current += $grant['remaining'];
            }
        }

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(days), 0) FROM comp_leave_entries WHERE employee_id = ? AND status = 'registered'");
        $stmt->execute([$employeeId]);
        $scheduled = (float)$stmt->fetchColumn();

        return [
            'current' => $current,
            'scheduled' => $scheduled,
            'available' => $current - $scheduled,
        ];
    }

    /**
     * 休日出勤の勤怠連絡から代休1日分を自動発生させる（§8）。呼び出し側の
     * トランザクション内で実行する。発生日は休日出勤の対象日。
     */
    public static function generateForHolidayWork(int $employeeId, string $occurredOn, ?int $noticeId, ?int $actorId = null): int
    {
        $pdo = Database::connection();
        $fiscalYear = self::fiscalYear($occurredOn);
        $expiresOn = self::expiryFor($occurredOn);
        if (!$pdo->inTransaction()) {
            throw new \LogicException('代休の自動付与はトランザクション内で実行してください。');
        }
        // Serialize all automatic grants for this employee, including first-ever grants.
        $lock = $pdo->prepare('SELECT id FROM employees WHERE id = ? FOR UPDATE');
        $lock->execute([$employeeId]);
        if (!$lock->fetchColumn()) {
            throw new DomainException('社員が見つかりません。');
        }
        // Keep administrative cancellations effective; repeated clock-ins must not restore them.
        $existing = $pdo->prepare('SELECT id FROM comp_leave_grants WHERE employee_id = ? AND occurred_on = ? ORDER BY id LIMIT 1 FOR UPDATE');
        $existing->execute([$employeeId, $occurredOn]);
        if ($existing->fetchColumn()) {
            return 0;
        }
        $stmt = $pdo->prepare('INSERT INTO comp_leave_grants (employee_id, occurred_on, fiscal_year, days, expires_on, notice_id, status, created_by, created_at, updated_at) VALUES (?, ?, ?, 1.0, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$employeeId, $occurredOn, $fiscalYear, $expiresOn, $noticeId, 'active', $actorId ?? Auth::id()]);
        $id = (int)$pdo->lastInsertId();
        Audit::log('comp_leave_generated', 'comp_leave_grant', $id, null, ['employee_id' => $employeeId, 'occurred_on' => $occurredOn, 'expires_on' => $expiresOn, 'notice_id' => $noticeId], $actorId);
        return $id;
    }

    /** 代休の取得予定を登録する。残数チェックと登録を同一トランザクションで行う。 */
    public static function createEntry(int $employeeId, array $input, ?int $actorId = null): int
    {
        $date = (string)($input['leave_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new DomainException('取得日を正しく入力してください。');
        }
        $isAdmin = (Auth::user()['role'] ?? 'employee') === 'admin';
        if ($date < date('Y-m-d') && !$isAdmin) {
            throw new DomainException('過去日の代休は登録できません。管理者へ訂正を依頼してください。');
        }
        $days = 1.0; // 初期版は1日単位のみ
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            // 同時登録による残数の整合性崩れを防ぐため社員行をロックする。
            $stmt = $pdo->prepare('SELECT id FROM employees WHERE id = ? FOR UPDATE');
            $stmt->execute([$employeeId]);
            if (!$stmt->fetch()) {
                throw new DomainException('対象の社員が見つかりません。');
            }
            if (self::summary($employeeId)['available'] < $days) {
                throw new DomainException('代休の残数が不足しています。');
            }
            $stmt = $pdo->prepare("SELECT id FROM comp_leave_entries WHERE employee_id = ? AND leave_date = ? AND status <> 'cancelled' FOR UPDATE");
            $stmt->execute([$employeeId, $date]);
            if ($stmt->fetch()) {
                throw new DomainException('同じ日付の代休がすでに登録されています。');
            }
            $status = $date < date('Y-m-d') ? 'taken' : 'registered';
            $stmt = $pdo->prepare('INSERT INTO comp_leave_entries (employee_id, leave_date, days, status, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$employeeId, $date, $days, $status, trim((string)($input['note'] ?? '')), $actorId ?? Auth::id()]);
            $id = (int)$pdo->lastInsertId();
            Audit::log('comp_leave_created', 'comp_leave_entry', $id, null, ['employee_id' => $employeeId, 'date' => $date, 'days' => $days], $actorId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** 代休の取得予定を取り消す。 */
    public static function cancelEntry(int $entryId, string $reason, bool $isAdmin = false): void
    {
        if (trim($reason) === '') {
            throw new DomainException('取消理由を入力してください。');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM comp_leave_entries WHERE id = ? FOR UPDATE');
            $stmt->execute([$entryId]);
            $entry = $stmt->fetch();
            if (!$entry || (!$isAdmin && (int)$entry['employee_id'] !== Auth::employeeId())) {
                throw new DomainException('対象の代休予定が見つかりません。');
            }
            if ($entry['status'] === 'cancelled') {
                throw new DomainException('この予定はすでに取消済みです。');
            }
            if (!$isAdmin && $entry['leave_date'] < date('Y-m-d')) {
                throw new DomainException('過去の代休は管理者だけが訂正できます。');
            }
            $stmt = $pdo->prepare("UPDATE comp_leave_entries SET status = 'cancelled', cancellation_reason = ?, cancelled_by = ?, cancelled_at = NOW(), updated_at = NOW() WHERE id = ?");
            $stmt->execute([trim($reason), Auth::id(), $entryId]);
            Audit::log('comp_leave_cancelled', 'comp_leave_entry', $entryId, $entry, ['status' => 'cancelled', 'reason' => trim($reason)]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 代休の発生（付与）を取り消す。管理者による訂正・取消の入口。
     * 休日出勤の訂正・取消に連動させる場合もこの処理を用いる（§7.8）。
     */
    public static function cancelGrant(int $grantId, string $reason, ?int $actorId = null): void
    {
        if (trim($reason) === '') {
            throw new DomainException('取消理由を入力してください。');
        }
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare('SELECT * FROM comp_leave_grants WHERE id = ? FOR UPDATE');
            $stmt->execute([$grantId]);
            $grant = $stmt->fetch();
            if (!$grant) {
                throw new DomainException('対象の代休発生記録が見つかりません。');
            }
            if ($grant['status'] === 'cancelled') {
                throw new DomainException('この代休はすでに取消済みです。');
            }
            $stmt = $pdo->prepare("UPDATE comp_leave_grants SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), updated_at = NOW() WHERE id = ?");
            $stmt->execute([$actorId ?? Auth::id(), $grantId]);
            Audit::log('comp_leave_grant_cancelled', 'comp_leave_grant', $grantId, $grant, ['status' => 'cancelled', 'reason' => trim($reason)], $actorId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * 指定した勤怠連絡（休日出勤）に紐づく未取消の代休発生を連動取消する（§7.8）。
     * 勤怠連絡の訂正・取消機能を追加した際に、その処理内から呼び出す。
     */
    public static function cancelGrantsForNotice(int $noticeId, string $reason, ?int $actorId = null): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT id FROM comp_leave_grants WHERE notice_id = ? AND status = 'active'");
        $stmt->execute([$noticeId]);
        $count = 0;
        foreach ($stmt->fetchAll() as $row) {
            self::cancelGrant((int)$row['id'], $reason, $actorId);
            $count++;
        }
        return $count;
    }
}
