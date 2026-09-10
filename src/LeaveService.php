<?php
declare(strict_types=1);

namespace App;

use DomainException;

final class LeaveService
{
    public static function summary(int $employeeId): array
    {
        $pdo = Database::connection();
        $today = date('Y-m-d');
        $stmt = $pdo->prepare('SELECT leave_renewal_month FROM employees WHERE id = ?');
        $stmt->execute([$employeeId]);
        $renewalMonthValue = $stmt->fetchColumn();
        $renewalConfigured = $renewalMonthValue !== false && $renewalMonthValue !== null;
        $renewalMonth = (int)($renewalMonthValue ?: 1);
        $currentGrantYear = (int)date('Y') - ((int)date('n') < $renewalMonth ? 1 : 0);
        $previousGrantYear = $currentGrantYear - 1;
        $stmt = $pdo->prepare("SELECT MAX(grant_year) FROM leave_adjustments WHERE employee_id = ? AND days_delta < 0 AND reason = 'CSV初期移行：初回付与前の前借'");
        $stmt->execute([$employeeId]);
        $advanceGrantYear = (int)$stmt->fetchColumn();
        if ($advanceGrantYear > $currentGrantYear) {
            $currentGrantYear = $advanceGrantYear;
            $previousGrantYear = $currentGrantYear - 1;
        }
        $stmt = $pdo->prepare('SELECT id, granted_on, grant_year, expires_on, days FROM leave_grants WHERE employee_id = ? AND granted_on <= CURDATE()');
        $stmt->execute([$employeeId]);
        $grants = array_map(static function (array $grant): array {
            return $grant + [
                'effective_expires_on' => $grant['expires_on'],
                'remaining' => (float)$grant['days'],
            ];
        }, $stmt->fetchAll());
        foreach ($grants as $grant) {
            if ((int)$grant['grant_year'] > $currentGrantYear && $grant['granted_on'] <= $today && $grant['effective_expires_on'] >= $today) {
                $currentGrantYear = (int)$grant['grant_year'];
                $previousGrantYear = $currentGrantYear - 1;
            }
        }
        usort($grants, static function (array $a, array $b): int {
            // 社内運用に合わせ、今期付与分から先に消化する。
            $yearOrder = (int)$b['grant_year'] <=> (int)$a['grant_year'];
            if ($yearOrder !== 0) return $yearOrder;
            return [$a['effective_expires_on'], (int)$a['id']] <=> [$b['effective_expires_on'], (int)$b['id']];
        });

        // Replay taken leave against the grant that expired first. This keeps an
        // already-used expired grant from reducing today's balance a second time.
        $stmt = $pdo->prepare("SELECT leave_date, days FROM leave_entries WHERE employee_id = ? AND status IN ('registered','approved','taken') AND leave_date < CURDATE() ORDER BY leave_date, id");
        $stmt->execute([$employeeId]);
        $unfundedTaken = 0.0;
        foreach ($stmt->fetchAll() as $entry) {
            $toConsume = (float)$entry['days'];
            foreach ($grants as &$grant) {
                if ($toConsume <= 0.0) break;
                if ($grant['granted_on'] > $entry['leave_date'] || $grant['effective_expires_on'] < $entry['leave_date'] || $grant['remaining'] <= 0.0) continue;
                $amount = min($toConsume, $grant['remaining']);
                $grant['remaining'] -= $amount;
                $toConsume -= $amount;
            }
            unset($grant);
            $unfundedTaken += $toConsume;
        }
        $previousYearRemaining = 0.0;
        $currentYearRemaining = 0.0;
        foreach ($grants as $grant) {
            if ($grant['effective_expires_on'] < $today) continue;
            if ((int)$grant['grant_year'] === $previousGrantYear) {
                $previousYearRemaining += $grant['remaining'];
            } elseif ((int)$grant['grant_year'] === $currentGrantYear) {
                $currentYearRemaining += $grant['remaining'];
            }
        }

        $stmt = $pdo->prepare('SELECT grant_year, COALESCE(SUM(days_delta), 0) AS days FROM leave_adjustments WHERE employee_id = ? GROUP BY grant_year');
        $stmt->execute([$employeeId]);
        foreach ($stmt->fetchAll() as $adjustment) {
            if ((int)$adjustment['grant_year'] === $previousGrantYear) {
                $previousYearRemaining += (float)$adjustment['days'];
            } elseif ((int)$adjustment['grant_year'] === $currentGrantYear) {
                $currentYearRemaining += (float)$adjustment['days'];
            }
        }

        // If imported history has no matching grant, consume the current bucket first.
        $fromCurrent = min(max($currentYearRemaining, 0.0), $unfundedTaken);
        $currentYearRemaining -= $fromCurrent;
        $unfundedTaken -= $fromCurrent;
        $previousYearRemaining -= $unfundedTaken;

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(days), 0) FROM leave_entries WHERE employee_id = ? AND status IN ('pending','registered','approved') AND leave_date >= CURDATE()");
        $stmt->execute([$employeeId]);
        $scheduled = (float)$stmt->fetchColumn();

        $current = $previousYearRemaining + $currentYearRemaining;
        $scheduledToCurrent = min(max($currentYearRemaining, 0.0), $scheduled);
        $forecastCurrentYear = $currentYearRemaining - $scheduledToCurrent;
        $forecastPreviousYear = $previousYearRemaining - ($scheduled - $scheduledToCurrent);
        $renewalDate = new \DateTimeImmutable(sprintf('%04d-%02d-01', (int)date('Y'), $renewalMonth));
        $todayDate = new \DateTimeImmutable($today);
        // 更新日当日は新年度へ切り替わっているため、警告対象は翌年の更新日。
        if ($renewalDate <= $todayDate) {
            $renewalDate = $renewalDate->modify('+1 year');
        }
        $daysUntilRenewal = (int)$todayDate->diff($renewalDate)->format('%a');
        return [
            'current' => $current,
            'previous_year' => $previousYearRemaining,
            'current_year' => $currentYearRemaining,
            'scheduled' => $scheduled,
            'forecast' => $current - $scheduled,
            'forecast_previous_year' => $forecastPreviousYear,
            'forecast_current_year' => $forecastCurrentYear,
            // 更新日時点で前年繰越は対象年度外となる。60日前から画面通知に使用する。
            'renewal_date' => $renewalDate->format('Y-m-d'),
            'days_until_renewal' => $daysUntilRenewal,
            'expiring_at_renewal' => $renewalConfigured ? max(0.0, $forecastPreviousYear) : 0.0,
        ];
    }

    public static function create(int $employeeId, array $input, ?int $actorId = null): int
    {
        $types = ['full' => 1.0, 'am' => 0.5, 'pm' => 0.5];
        $type = (string)($input['leave_type'] ?? '');
        $date = (string)($input['leave_date'] ?? '');
        if (!isset($types[$type]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new DomainException('取得日と取得区分を正しく入力してください。');
        }
        if ($date < date('Y-m-d') && (Auth::user()['role'] ?? 'employee') !== 'admin') {
            throw new DomainException('過去日の有給は登録できません。管理者へ訂正を依頼してください。');
        }
        $days = $types[$type];
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            // Serialize leave registrations per employee so concurrent requests cannot overspend.
            $stmt = $pdo->prepare('SELECT id FROM employees WHERE id = ? FOR UPDATE');
            $stmt->execute([$employeeId]);
            if (!$stmt->fetch()) {
                throw new DomainException('対象の社員が見つかりません。');
            }
            // 前借（残数を超えた事前申請）を許可する運用では残数不足でブロックしない。
            // 更新月などの付与予定を待つケースでも弾かれないようにするため既定は許可。
            if (!Settings::bool('leave_allow_advance', true) && self::summary($employeeId)['forecast'] < $days) {
                throw new DomainException('予定反映後の残数が不足しています。');
            }
            $stmt = $pdo->prepare("SELECT id FROM leave_entries WHERE employee_id = ? AND leave_date = ? AND status NOT IN ('cancelled','rejected') FOR UPDATE");
            $stmt->execute([$employeeId, $date]);
            if ($stmt->fetch()) {
                throw new DomainException('同じ日付の有給がすでに登録されています。');
            }
            $isAdmin = (Auth::user()['role'] ?? 'employee') === 'admin';
            $status = $date < date('Y-m-d') ? 'taken' : (!$isAdmin && Settings::bool('leave_approval_required', true) ? 'pending' : 'registered');
            $stmt = $pdo->prepare('INSERT INTO leave_entries (employee_id, leave_date, leave_type, days, status, note, confirmed_with, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$employeeId, $date, $type, $days, $status, trim((string)($input['note'] ?? '')), trim((string)($input['confirmed_with'] ?? '')), $actorId ?? Auth::id()]);
            $id = (int)$pdo->lastInsertId();
            Audit::log('leave_created', 'leave_entry', $id, null, ['employee_id' => $employeeId, 'date' => $date, 'type' => $type, 'days' => $days, 'status' => $status], $actorId);
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

    public static function cancel(int $entryId, string $reason, bool $isAdmin = false): void
    {
        if (trim($reason) === '') {
            throw new DomainException('取消理由を入力してください。');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM leave_entries WHERE id = ? FOR UPDATE');
            $stmt->execute([$entryId]);
            $entry = $stmt->fetch();
            if (!$entry || (!$isAdmin && (int)$entry['employee_id'] !== Auth::employeeId())) {
                throw new DomainException('対象の有給予定が見つかりません。');
            }
            if (in_array($entry['status'], ['cancelled', 'rejected'], true)) {
                throw new DomainException('この予定は取消済みまたは却下済みです。');
            }
            if (!$isAdmin && $entry['leave_date'] < date('Y-m-d')) {
                throw new DomainException('過去の有給は管理者だけが訂正できます。');
            }
            $stmt = $pdo->prepare("UPDATE leave_entries SET status = 'cancelled', cancellation_reason = ?, cancelled_by = ?, cancelled_at = NOW(), updated_at = NOW() WHERE id = ?");
            $stmt->execute([trim($reason), Auth::id(), $entryId]);
            Audit::log('leave_cancelled', 'leave_entry', $entryId, $entry, ['status' => 'cancelled', 'reason' => trim($reason)]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function review(int $entryId, string $decision, string $reason = ''): void
    {
        if (!in_array($decision, ['approve', 'reject'], true)) throw new DomainException('承認操作が正しくありません。');
        if ($decision === 'reject' && trim($reason) === '') throw new DomainException('却下理由を入力してください。');
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM leave_entries WHERE id = ? FOR UPDATE');
            $stmt->execute([$entryId]);
            $entry = $stmt->fetch();
            if (!$entry || $entry['status'] !== 'pending') throw new DomainException('承認待ちの有給予定が見つかりません。');
            if ($decision === 'approve') {
                $status = $entry['leave_date'] < date('Y-m-d') ? 'taken' : 'approved';
                $pdo->prepare('UPDATE leave_entries SET status=?, reviewed_by=?, reviewed_at=NOW(), rejection_reason=NULL, updated_at=NOW() WHERE id=?')->execute([$status, Auth::id(), $entryId]);
                Audit::log('leave_approved', 'leave_entry', $entryId, $entry, ['status' => $status]);
            } else {
                $pdo->prepare("UPDATE leave_entries SET status='rejected', reviewed_by=?, reviewed_at=NOW(), rejection_reason=?, updated_at=NOW() WHERE id=?")->execute([Auth::id(), trim($reason), $entryId]);
                Audit::log('leave_rejected', 'leave_entry', $entryId, $entry, ['status' => 'rejected', 'reason' => trim($reason)]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
