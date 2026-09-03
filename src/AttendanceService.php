<?php
declare(strict_types=1);

namespace App;

/**
 * 出退勤の集計（§3.2 月次集計、§9）。
 *
 * 出勤/退勤イベントを「業務日＝出勤日」でペアにし（日跨ぎ退勤に対応）、
 * 月内の出勤日数・勤務時間を算出する。休憩・残業・所定労働のルールは未確定（§9.2）
 * のため、初期版は打刻の素の勤務時間（退勤−出勤）を扱う。
 */
final class AttendanceService
{
    /**
     * 指定月の出退勤ペアを返す。employeeId=null で全社員。
     * 各要素: employee_id, full_name, employee_code, date(業務日), clock_in, clock_out(いずれもNULL可)。
     * 出勤のみ（未退勤）や退勤のみ（孤立）も1件として含める。
     */
    public static function pairs(string $month, ?int $employeeId = null): array
    {
        $start = $month . '-01 00:00:00';
        // 末日の出勤に対する日跨ぎ退勤も拾うため、取得窓は翌月+2日まで広げる。
        $fetchEnd = date('Y-m-d 00:00:00', strtotime($month . '-01 +1 month +2 day'));
        $monthStartDate = $month . '-01';
        $monthEndDate = date('Y-m-t', strtotime($month . '-01'));

        $pdo = Database::connection();
        $sql = 'SELECT ae.employee_id, e.full_name, e.employee_code, ae.event_type, ae.occurred_at
                FROM attendance_events ae JOIN employees e ON e.id = ae.employee_id
                WHERE ae.occurred_at >= ? AND ae.occurred_at < ?';
        $params = [$start, $fetchEnd];
        if ($employeeId !== null) {
            $sql .= ' AND ae.employee_id = ?';
            $params[] = $employeeId;
        }
        $sql .= ' ORDER BY ae.employee_id, ae.occurred_at, ae.id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        $flush = static function (?array $in, ?array $out) use (&$rows, $monthStartDate, $monthEndDate): void {
            $anchor = $in ?? $out;
            if ($anchor === null) {
                return;
            }
            $date = substr((string)$anchor['occurred_at'], 0, 10);
            if ($date < $monthStartDate || $date > $monthEndDate) {
                return; // 業務日が対象月内のもののみ
            }
            $rows[] = [
                'employee_id' => (int)$anchor['employee_id'],
                'full_name' => (string)$anchor['full_name'],
                'employee_code' => $anchor['employee_code'],
                'date' => $date,
                'clock_in' => $in ? (string)$in['occurred_at'] : null,
                'clock_out' => $out ? (string)$out['occurred_at'] : null,
            ];
        };

        $pending = null;
        $currentEmployee = null;
        foreach ($stmt as $ev) {
            if ($currentEmployee !== null && (int)$ev['employee_id'] !== $currentEmployee && $pending !== null) {
                $flush($pending, null);
                $pending = null;
            }
            $currentEmployee = (int)$ev['employee_id'];
            if ($ev['event_type'] === 'clock_in') {
                if ($pending !== null) {
                    $flush($pending, null);
                }
                $pending = $ev;
            } else {
                if ($pending !== null) {
                    $flush($pending, $ev);
                    $pending = null;
                } else {
                    $flush(null, $ev);
                }
            }
        }
        if ($pending !== null) {
            $flush($pending, null);
        }
        return $rows;
    }

    /**
     * 指定月の社員別集計。各要素: employee_id, full_name, employee_code,
     * worked_days（出勤日数）, total_minutes（勤務分の合計）, incomplete（未退勤件数）。
     * 氏名順。打刻のない社員は含めない。
     */
    public static function monthlySummary(string $month): array
    {
        $summary = [];
        foreach (self::pairs($month) as $row) {
            $id = $row['employee_id'];
            if (!isset($summary[$id])) {
                $summary[$id] = [
                    'employee_id' => $id,
                    'full_name' => $row['full_name'],
                    'employee_code' => $row['employee_code'],
                    'days' => [],
                    'total_minutes' => 0,
                    'incomplete' => 0,
                ];
            }
            $summary[$id]['days'][$row['date']] = true; // 出勤日（業務日）
            if ($row['clock_in'] !== null && $row['clock_out'] !== null) {
                $summary[$id]['total_minutes'] += self::minutesBetween($row['clock_in'], $row['clock_out']);
            } elseif ($row['clock_in'] !== null && $row['clock_out'] === null) {
                $summary[$id]['incomplete']++;
            }
        }
        $result = array_map(static function (array $s): array {
            return [
                'employee_id' => $s['employee_id'],
                'full_name' => $s['full_name'],
                'employee_code' => $s['employee_code'],
                'worked_days' => count($s['days']),
                'total_minutes' => $s['total_minutes'],
                'incomplete' => $s['incomplete'],
            ];
        }, $summary);
        usort($result, static fn(array $a, array $b): int => strcmp((string)$a['full_name'], (string)$b['full_name']));
        return $result;
    }

    /** 2つの日時文字列の差（分、切り捨て）。 */
    public static function minutesBetween(string $from, string $to): int
    {
        return (int)floor((strtotime($to) - strtotime($from)) / 60);
    }

    /** 分を「H:MM」表記にする。 */
    public static function formatMinutes(int $minutes): string
    {
        if ($minutes < 0) {
            $minutes = 0;
        }
        return intdiv($minutes, 60) . ':' . str_pad((string)($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
