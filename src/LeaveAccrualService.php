<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/**
 * 入社月にもとづく有給休暇の自動付与（法定日数）。
 *
 * 付与ルール（管理者と合意した方針）:
 *  - 各社員の更新月（employees.leave_renewal_month。通常は入社月）に付与する。
 *  - 初回付与は「入社日の6か月後」以降で最初に到来する更新月。以後は毎年その月。
 *  - 付与日数は勤続に応じた法定日数テーブル（付与回数で判定）:
 *      1回目=10日 / 2回目=11 / 3回目=12 / 4回目=14 / 5回目=16 / 6回目=18 / 7回目以降=20日
 *  - 有効期限は付与日の2年後の前日（法定の時効2年）。
 *  - source='auto_hire' で記録し、同一社員・同一付与年度では二重付与しない（冪等）。
 *
 * hired_on または leave_renewal_month が未設定の社員は対象外（従来どおり手動付与）。
 */
final class LeaveAccrualService
{
    /** 付与回数（1始まり）に対応する法定付与日数。 */
    private const SCHEDULE = [10, 11, 12, 14, 16, 18, 20];

    /** 付与回数に対応する法定日数を返す（7回目以降は20日で頭打ち）。 */
    public static function daysForGrantNumber(int $number): float
    {
        $index = max(0, min($number - 1, count(self::SCHEDULE) - 1));
        return (float)self::SCHEDULE[$index];
    }

    /**
     * asOf 時点までに到来している付与予定を古い順に返す。
     * @return list<array{granted_on:string, number:int, days:float}>
     */
    public static function eligibleGrants(DateTimeImmutable $hiredOn, int $renewalMonth, DateTimeImmutable $asOf): array
    {
        if ($renewalMonth < 1 || $renewalMonth > 12) {
            return [];
        }
        // 初回付与は入社6か月後以降で最初に到来する更新月の1日。
        $eligibleFrom = $hiredOn->modify('+6 months');
        $candidate = (new DateTimeImmutable(sprintf('%04d-%02d-01', (int)$eligibleFrom->format('Y'), $renewalMonth)))->setTime(0, 0);
        if ($candidate < $eligibleFrom->setTime(0, 0)) {
            $candidate = $candidate->modify('+1 year');
        }
        $asOfDay = $asOf->setTime(0, 0);
        $grants = [];
        for ($number = 1; $candidate <= $asOfDay; $number++) {
            $grants[] = [
                'granted_on' => $candidate->format('Y-m-d'),
                'number' => $number,
                'days' => self::daysForGrantNumber($number),
            ];
            $candidate = $candidate->modify('+1 year');
        }
        return $grants;
    }

    /**
     * 1社員に対して未付与の自動付与を作成する。作成件数を返す。冪等。
     */
    public static function accrueForEmployee(int $employeeId, ?DateTimeImmutable $asOf = null): int
    {
        $asOf ??= new DateTimeImmutable('today');
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT hired_on, leave_renewal_month FROM employees WHERE id = ?');
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();
        if (!$employee || empty($employee['hired_on']) || $employee['leave_renewal_month'] === null) {
            return 0;
        }
        $renewalMonth = (int)$employee['leave_renewal_month'];
        $hiredOn = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$employee['hired_on']);
        if (!$hiredOn || $renewalMonth < 1 || $renewalMonth > 12) {
            return 0;
        }
        $eligible = self::eligibleGrants($hiredOn, $renewalMonth, $asOf);
        if ($eligible === []) {
            return 0;
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            // 社員行をロックし、ログイン時とcronの同時実行でも二重付与しないようにする。
            $lock = $pdo->prepare('SELECT id FROM employees WHERE id = ? FOR UPDATE');
            $lock->execute([$employeeId]);

            $existing = $pdo->prepare("SELECT grant_year FROM leave_grants WHERE employee_id = ? AND source = 'auto_hire'");
            $existing->execute([$employeeId]);
            $done = array_flip(array_map('intval', $existing->fetchAll(\PDO::FETCH_COLUMN)));

            $insert = $pdo->prepare(
                "INSERT INTO leave_grants (employee_id, granted_on, grant_year, days, expires_on, reason, source, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'auto_hire', NULL, NOW())"
            );
            $inserted = 0;
            foreach ($eligible as $grant) {
                $grantYear = (int)substr($grant['granted_on'], 0, 4);
                if (isset($done[$grantYear])) {
                    continue;
                }
                $expiresOn = (new DateTimeImmutable($grant['granted_on']))->modify('+2 years -1 day')->format('Y-m-d');
                $reason = sprintf('入社月による自動付与（%d回目：%s日）', $grant['number'], self::formatDays($grant['days']));
                $insert->execute([$employeeId, $grant['granted_on'], $grantYear, $grant['days'], $expiresOn, $reason]);
                Audit::log('leave_auto_granted', 'leave_grant', (int)$pdo->lastInsertId(), null, [
                    'employee_id' => $employeeId,
                    'granted_on' => $grant['granted_on'],
                    'grant_year' => $grantYear,
                    'days' => $grant['days'],
                    'number' => $grant['number'],
                ], null);
                $done[$grantYear] = true;
                $inserted++;
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $inserted;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * 在籍中の全社員に自動付与を実行する（cron/バッチ用）。
     * @return array{employees:int, granted:int}
     */
    public static function accrueAll(?DateTimeImmutable $asOf = null): array
    {
        $pdo = Database::connection();
        $ids = $pdo->query("SELECT e.id FROM employees e JOIN users u ON u.employee_id = e.id WHERE u.status = 'active' AND e.hired_on IS NOT NULL AND e.leave_renewal_month IS NOT NULL")
            ->fetchAll(\PDO::FETCH_COLUMN);
        $granted = 0;
        foreach ($ids as $id) {
            $granted += self::accrueForEmployee((int)$id, $asOf);
        }
        return ['employees' => count($ids), 'granted' => $granted];
    }

    private static function formatDays(float $days): string
    {
        return rtrim(rtrim(number_format($days, 1), '0'), '.');
    }
}
