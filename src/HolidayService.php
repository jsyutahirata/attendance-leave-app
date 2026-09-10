<?php
declare(strict_types=1);

namespace App;

use DomainException;

final class HolidayService
{
    public const SOURCE_URL = 'https://www8.cao.go.jp/chosei/shukujitsu/syukujitsu.csv';

    public static function forMonth(string $month): array
    {
        $stmt = Database::connection()->prepare('SELECT holiday_date, name FROM japanese_holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date');
        $stmt->execute([$month . '-01', date('Y-m-t', strtotime($month . '-01'))]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            $rows = self::builtInForMonth($month);
        }
        return array_map(static fn(array $holiday): array => [
            'id' => null,
            'event_type' => 'public_holiday',
            'title' => $holiday['name'],
            'start_date' => $holiday['holiday_date'],
            'end_date' => $holiday['holiday_date'],
            'start_time' => null,
            'end_time' => null,
            'notes' => null,
        ], $rows);
    }

    public static function lastSyncedAt(): ?string
    {
        $value = Database::connection()->query('SELECT MAX(synced_at) FROM japanese_holidays')->fetchColumn();
        return $value === false || $value === null ? null : (string)$value;
    }

    public static function sync(): array
    {
        $body = self::download();
        $holidays = self::parseCsv($body);
        if (count($holidays) < 100) {
            throw new DomainException('公式CSVの件数が少なすぎるため、祝日データを更新しませんでした。');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM japanese_holidays');
            $stmt = $pdo->prepare('INSERT INTO japanese_holidays (holiday_date, name, source_url, synced_at) VALUES (?, ?, ?, NOW())');
            foreach ($holidays as $date => $name) {
                $stmt->execute([$date, $name, self::SOURCE_URL]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return ['count' => count($holidays), 'latest' => array_key_last($holidays)];
    }

    public static function parseCsv(string $body): array
    {
        if ($body === '') throw new DomainException('公式CSVが空でした。');
        $encoding = mb_detect_encoding($body, ['UTF-8', 'SJIS-win'], true) ?: 'SJIS-win';
        $utf8 = mb_convert_encoding($body, 'UTF-8', $encoding);
        $utf8 = preg_replace('/^\xEF\xBB\xBF/', '', $utf8) ?? $utf8;
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) throw new DomainException('祝日CSVを解析できませんでした。');
        fwrite($stream, $utf8);
        rewind($stream);
        $holidays = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            $rawDate = trim((string)($row[0] ?? ''));
            $name = trim((string)($row[1] ?? ''));
            if (!preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $rawDate, $matches) || $name === '') continue;
            $year = (int)$matches[1]; $month = (int)$matches[2]; $day = (int)$matches[3];
            if (!checkdate($month, $day, $year)) continue;
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $holidays[$date] = mb_substr($name, 0, 100);
        }
        fclose($stream);
        ksort($holidays);
        return $holidays;
    }

    private static function download(): string
    {
        if (function_exists('curl_init')) {
            $curl = curl_init(self::SOURCE_URL);
            if ($curl === false) throw new DomainException('祝日CSVの取得を開始できませんでした。');
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'AttendanceLeaveApp/1.0',
            ]);
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            $body = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            if (!is_string($body) || $status !== 200) {
                throw new DomainException('公式CSVを取得できませんでした。HTTP ' . $status . ($error !== '' ? ' / ' . $error : ''));
            }
            return $body;
        }

        $context = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'AttendanceLeaveApp/1.0']]);
        $body = @file_get_contents(self::SOURCE_URL, false, $context);
        if (!is_string($body)) throw new DomainException('公式CSVを取得できませんでした。');
        return $body;
    }

    private static function builtInForMonth(string $month): array
    {
        $holidays = [
            '2026-01-01'=>'元日', '2026-01-12'=>'成人の日', '2026-02-11'=>'建国記念の日', '2026-02-23'=>'天皇誕生日',
            '2026-03-20'=>'春分の日', '2026-04-29'=>'昭和の日', '2026-05-03'=>'憲法記念日', '2026-05-04'=>'みどりの日',
            '2026-05-05'=>'こどもの日', '2026-05-06'=>'振替休日', '2026-07-20'=>'海の日', '2026-08-11'=>'山の日',
            '2026-09-21'=>'敬老の日', '2026-09-22'=>'国民の休日', '2026-09-23'=>'秋分の日', '2026-10-12'=>'スポーツの日',
            '2026-11-03'=>'文化の日', '2026-11-23'=>'勤労感謝の日',
            '2027-01-01'=>'元日', '2027-01-11'=>'成人の日', '2027-02-11'=>'建国記念の日', '2027-02-23'=>'天皇誕生日',
            '2027-03-21'=>'春分の日', '2027-03-22'=>'振替休日', '2027-04-29'=>'昭和の日', '2027-05-03'=>'憲法記念日',
            '2027-05-04'=>'みどりの日', '2027-05-05'=>'こどもの日', '2027-07-19'=>'海の日', '2027-08-11'=>'山の日',
            '2027-09-20'=>'敬老の日', '2027-09-23'=>'秋分の日', '2027-10-11'=>'スポーツの日', '2027-11-03'=>'文化の日',
            '2027-11-23'=>'勤労感謝の日',
        ];
        $rows = [];
        foreach ($holidays as $date => $name) {
            if (str_starts_with($date, $month . '-')) $rows[] = ['holiday_date' => $date, 'name' => $name];
        }
        return $rows;
    }
}
