<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DomainException;

final class CompanyCalendarService
{
    public const TYPES = [
        'public_holiday' => '祝日',
        'company_holiday' => '会社休日',
        'recommended_leave' => '一斉有給消化日',
        'all_hands' => '全体定例会議',
        'other' => 'その他',
    ];

    public static function normalizeMonth(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $matches)
            && checkdate((int)$matches[2], 1, (int)$matches[1])) {
            return $value;
        }
        return date('Y-m');
    }

    public static function monthBounds(string $month): array
    {
        $first = new DateTimeImmutable($month . '-01');
        return [
            'first' => $first->format('Y-m-d'),
            'last' => $first->modify('last day of this month')->format('Y-m-d'),
            'previous' => $first->modify('-1 month')->format('Y-m'),
            'next' => $first->modify('+1 month')->format('Y-m'),
            'label' => $first->format('Y年n月'),
        ];
    }

    public static function companyEventsForMonth(string $month): array
    {
        $bounds = self::monthBounds($month);
        $stmt = Database::connection()->prepare(
            'SELECT * FROM company_calendar_events WHERE start_date <= ? AND end_date >= ? ORDER BY start_date, start_time, id'
        );
        $stmt->execute([$bounds['last'], $bounds['first']]);
        return $stmt->fetchAll();
    }

    private static function displayEventsForMonth(string $month): array
    {
        $events = HolidayService::forMonth($month);
        $officialDates = array_fill_keys(array_column($events, 'start_date'), true);
        foreach (self::companyEventsForMonth($month) as $event) {
            // 公表済みの祝日と同じ日に手入力された祝日は二重表示しない。
            if ($event['event_type'] === 'public_holiday' && isset($officialDates[$event['start_date']])) continue;
            $events[] = $event;
        }
        usort($events, static fn(array $a, array $b): int => [$a['start_date'], (string)$a['start_time']] <=> [$b['start_date'], (string)$b['start_time']]);
        return $events;
    }

    public static function calendar(int $employeeId, string $month): array
    {
        $month = self::normalizeMonth($month);
        $bounds = self::monthBounds($month);
        $itemsByDate = [];

        foreach (self::displayEventsForMonth($month) as $event) {
            $start = max($event['start_date'], $bounds['first']);
            $end = min($event['end_date'], $bounds['last']);
            for ($date = new DateTimeImmutable($start), $last = new DateTimeImmutable($end); $date <= $last; $date = $date->modify('+1 day')) {
                $key = $date->format('Y-m-d');
                $time = $event['start_time'] ? substr((string)$event['start_time'], 0, 5) : '';
                $itemsByDate[$key][] = [
                    'class' => 'company-' . $event['event_type'],
                    'label' => ($time !== '' ? $time . ' ' : '') . $event['title'],
                    'detail' => trim((string)($event['notes'] ?? '')),
                ];
            }
        }

        $pdo = Database::connection();
        $leave = $pdo->prepare("SELECT leave_date, leave_type, status, note FROM leave_entries WHERE employee_id = ? AND leave_date BETWEEN ? AND ? AND status NOT IN ('cancelled','rejected') ORDER BY leave_date, id");
        $leave->execute([$employeeId, $bounds['first'], $bounds['last']]);
        $leaveLabels = ['full' => '有給（1日）', 'am' => '有給（午前）', 'pm' => '有給（午後）'];
        foreach ($leave->fetchAll() as $entry) {
            $itemsByDate[$entry['leave_date']][] = [
                'class' => 'personal-leave',
                'label' => ($leaveLabels[$entry['leave_type']] ?? '有給') . ($entry['status'] === 'pending' ? '・承認待ち' : ''),
                'detail' => trim((string)($entry['note'] ?? '')),
            ];
        }

        $comp = $pdo->prepare("SELECT leave_date, note FROM comp_leave_entries WHERE employee_id = ? AND leave_date BETWEEN ? AND ? AND status <> 'cancelled' ORDER BY leave_date, id");
        $comp->execute([$employeeId, $bounds['first'], $bounds['last']]);
        foreach ($comp->fetchAll() as $entry) {
            $itemsByDate[$entry['leave_date']][] = [
                'class' => 'personal-comp-leave',
                'label' => '代休',
                'detail' => trim((string)($entry['note'] ?? '')),
            ];
        }

        $notices = $pdo->prepare('SELECT target_date, notice_type, expected_start, expected_end, details FROM attendance_notices WHERE employee_id = ? AND target_date BETWEEN ? AND ? AND leave_entry_id IS NULL ORDER BY target_date, id');
        $notices->execute([$employeeId, $bounds['first'], $bounds['last']]);
        $noticeLabels = ['late' => '遅刻', 'early' => '早退', 'absence' => '欠勤', 'holiday_work' => '休日出勤', 'medical' => '健康診断', 'other' => 'その他'];
        foreach ($notices->fetchAll() as $notice) {
            $time = '';
            if ($notice['expected_start']) $time .= substr((string)$notice['expected_start'], 0, 5) . '〜';
            if ($notice['expected_end']) $time .= substr((string)$notice['expected_end'], 0, 5);
            $itemsByDate[$notice['target_date']][] = [
                'class' => 'personal-notice',
                'label' => ($noticeLabels[$notice['notice_type']] ?? '勤怠連絡') . ($time !== '' ? ' ' . $time : ''),
                'detail' => trim((string)($notice['details'] ?? '')),
            ];
        }

        return self::buildGrid($month, $itemsByDate);
    }

    public static function companyCalendar(string $month): array
    {
        $month = self::normalizeMonth($month);
        $bounds = self::monthBounds($month);
        $itemsByDate = [];
        foreach (self::displayEventsForMonth($month) as $event) {
            $start = max($event['start_date'], $bounds['first']);
            $end = min($event['end_date'], $bounds['last']);
            for ($date = new DateTimeImmutable($start), $last = new DateTimeImmutable($end); $date <= $last; $date = $date->modify('+1 day')) {
                $key = $date->format('Y-m-d');
                $time = $event['start_time'] ? substr((string)$event['start_time'], 0, 5) : '';
                $itemsByDate[$key][] = [
                    'class' => 'company-' . $event['event_type'],
                    'label' => ($time !== '' ? $time . ' ' : '') . $event['title'],
                    'detail' => trim((string)($event['notes'] ?? '')),
                ];
            }
        }
        return self::buildGrid($month, $itemsByDate);
    }

    private static function buildGrid(string $month, array $itemsByDate): array
    {
        $bounds = self::monthBounds($month);
        $first = new DateTimeImmutable($bounds['first']);
        $last = new DateTimeImmutable($bounds['last']);
        $gridStart = $first->modify('-' . (int)$first->format('w') . ' days');
        $gridEnd = $last->modify('+' . (6 - (int)$last->format('w')) . ' days');
        $days = [];
        for ($date = $gridStart; $date <= $gridEnd; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $items = $itemsByDate[$key] ?? [];
            $days[] = [
                'date' => $key,
                'day' => $date->format('j'),
                'weekday' => (int)$date->format('w'),
                'current_month' => $date->format('Y-m') === $month,
                'today' => $key === date('Y-m-d'),
                'public_holiday' => (bool)array_filter($items, static fn(array $item): bool => $item['class'] === 'company-public_holiday'),
                'items' => $items,
            ];
        }
        return $bounds + ['month' => $month, 'days' => $days];
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM company_calendar_events WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function save(array $input): int
    {
        $id = (int)($input['event_id'] ?? 0);
        $type = (string)($input['event_type'] ?? '');
        $title = trim((string)($input['title'] ?? ''));
        $startDate = (string)($input['start_date'] ?? '');
        $endDate = (string)($input['end_date'] ?? '') ?: $startDate;
        $startTime = trim((string)($input['start_time'] ?? '')) ?: null;
        $endTime = trim((string)($input['end_time'] ?? '')) ?: null;
        $notes = trim((string)($input['notes'] ?? '')) ?: null;

        if (!isset(self::TYPES[$type]) || !self::validDate($startDate) || !self::validDate($endDate) || $endDate < $startDate) {
            throw new DomainException('種別と日付を正しく入力してください。');
        }
        if ($title === '' || mb_strlen($title) > 100) {
            throw new DomainException('予定名は100文字以内で入力してください。');
        }
        if ((new DateTimeImmutable($startDate))->diff(new DateTimeImmutable($endDate))->days > 366) {
            throw new DomainException('期間は1年以内で設定してください。');
        }
        if (($startTime !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $startTime))
            || ($endTime !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $endTime))
            || ($startTime !== null && $endTime !== null && $endTime <= $startTime)) {
            throw new DomainException('時刻を正しく入力してください。');
        }

        $pdo = Database::connection();
        $before = $id > 0 ? self::find($id) : null;
        if ($id > 0 && !$before) {
            throw new DomainException('編集対象の予定が見つかりません。');
        }
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE company_calendar_events SET event_type=?, title=?, start_date=?, end_date=?, start_time=?, end_time=?, notes=?, updated_by=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([$type, $title, $startDate, $endDate, $startTime, $endTime, $notes, Auth::id(), $id]);
            Audit::log('company_calendar_updated', 'company_calendar_event', $id, $before, self::find($id));
            return $id;
        }
        $stmt = $pdo->prepare('INSERT INTO company_calendar_events (event_type, title, start_date, end_date, start_time, end_time, notes, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$type, $title, $startDate, $endDate, $startTime, $endTime, $notes, Auth::id(), Auth::id()]);
        $id = (int)$pdo->lastInsertId();
        Audit::log('company_calendar_created', 'company_calendar_event', $id, null, self::find($id));
        return $id;
    }

    public static function delete(int $id): void
    {
        $before = self::find($id);
        if (!$before) {
            throw new DomainException('削除対象の予定が見つかりません。');
        }
        Database::connection()->prepare('DELETE FROM company_calendar_events WHERE id = ?')->execute([$id]);
        Audit::log('company_calendar_deleted', 'company_calendar_event', $id, $before, null);
    }

    private static function validDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) return false;
        return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]);
    }
}
