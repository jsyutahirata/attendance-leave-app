<?php
declare(strict_types=1);

namespace App;

use DomainException;

final class CalendarImportService
{
    private const MAX_BYTES = 5242880;

    public static function preview(array $file, string $selectedSheet): array
    {
        if (!in_array($selectedSheet, ['一般', '閏年'], true)) {
            throw new DomainException('取り込むシートを選択してください。');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($file['tmp_name'])) {
            throw new DomainException('Excelファイルを選択してください。');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_BYTES) throw new DomainException('Excelファイルは5MB以下にしてください。');
        $name = (string)($file['name'] ?? 'calendar.xlsx');
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') throw new DomainException('拡張子.xlsxのExcelファイルを選択してください。');
        $path = (string)$file['tmp_name'];
        $hash = hash_file('sha256', $path);
        if ($hash === false) throw new DomainException('Excelファイルを確認できませんでした。');

        $rows = self::markDuplicates(CalendarWorkbookReader::read($path, $selectedSheet));
        return [
            'source_name' => mb_substr($name, 0, 200),
            'source_hash' => $hash,
            'source_format' => 'J’sカレンダー AP～AV列',
            'selected_sheet' => $selectedSheet,
            'created_at' => time(),
            'rows' => $rows,
            'valid_count' => count(array_filter($rows, static fn(array $row): bool => !$row['errors'] && !$row['duplicate'])),
            'error_count' => count(array_filter($rows, static fn(array $row): bool => (bool)$row['errors'])),
            'duplicate_count' => count(array_filter($rows, static fn(array $row): bool => $row['duplicate'])),
        ];
    }

    private static function markDuplicates(array $rows): array
    {
        $find = Database::connection()->prepare('SELECT id FROM company_calendar_events WHERE event_type=? AND title=? AND start_date=? AND end_date=? LIMIT 1');
        $seen = [];
        foreach ($rows as &$row) {
            if ($row['errors']) continue;
            $key = implode('|', [$row['event_type'], $row['title'], $row['start_date'], $row['end_date']]);
            if (isset($seen[$key])) {
                $row['duplicate'] = true;
                continue;
            }
            $seen[$key] = true;
            $find->execute([$row['event_type'], $row['title'], $row['start_date'], $row['end_date']]);
            $row['duplicate'] = (bool)$find->fetch();
        }
        unset($row);
        return $rows;
    }

    public static function import(array $preview, int $actorId): array
    {
        if ((int)($preview['created_at'] ?? 0) < time() - 1800 || !isset($preview['rows']) || !is_array($preview['rows'])) {
            throw new DomainException('確認データの有効期限が切れました。Excelをもう一度選択してください。');
        }
        if ((int)($preview['error_count'] ?? 0) > 0) throw new DomainException('エラー行を修正してから、もう一度Excelを選択してください。');
        $pdo = Database::connection();
        $find = $pdo->prepare('SELECT id FROM company_calendar_events WHERE event_type=? AND title=? AND start_date=? AND end_date=? LIMIT 1');
        $insert = $pdo->prepare('INSERT INTO company_calendar_events (event_type, title, start_date, end_date, start_time, end_time, notes, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, NULL, NULL, ?, ?, NOW(), NOW())');
        $imported = 0; $skipped = 0;
        $pdo->beginTransaction();
        try {
            foreach ($preview['rows'] as $row) {
                if (!empty($row['duplicate'])) { $skipped++; continue; }
                $find->execute([$row['event_type'], $row['title'], $row['start_date'], $row['end_date']]);
                if ($find->fetch()) { $skipped++; continue; }
                $insert->execute([$row['event_type'], $row['title'], $row['start_date'], $row['end_date'], $actorId, $actorId]);
                $imported++;
            }
            Audit::log('company_calendar_import_completed', 'company_calendar_import', null, null, [
                'file_name' => $preview['source_name'] ?? '',
                'file_sha256' => $preview['source_hash'] ?? '',
                'imported' => $imported,
                'skipped' => $skipped,
            ], $actorId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }
}
