<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DomainException;

final class CalendarWorkbookReader
{
    private const MAX_ENTRY_BYTES = 8388608;
    private const FIRST_DATA_ROW = 5;
    private const LAST_DATA_ROW = 30;

    public static function read(string $path, ?string $selectedSheet = null): array
    {
        $archive = file_get_contents($path);
        if ($archive === false || $archive === '') {
            throw new DomainException('Excelファイルを読み込めませんでした。');
        }
        $entries = self::centralDirectory($archive);
        $workbookXml = self::entry($archive, $entries, 'xl/workbook.xml');
        $relationsXml = self::entry($archive, $entries, 'xl/_rels/workbook.xml.rels');
        $sharedStrings = isset($entries['xl/sharedStrings.xml'])
            ? self::sharedStrings(self::entry($archive, $entries, 'xl/sharedStrings.xml'))
            : [];

        $workbook = self::xml($workbookXml);
        $relations = self::xml($relationsXml);
        $relationTargets = [];
        foreach ((new \DOMXPath($relations))->query('//*[local-name()="Relationship"]') ?: [] as $relation) {
            $relationTargets[$relation->getAttribute('Id')] = self::normalizeWorksheetPath($relation->getAttribute('Target'));
        }
        $date1904 = false;
        $properties = (new \DOMXPath($workbook))->query('//*[local-name()="workbookPr"]')->item(0);
        if ($properties instanceof \DOMElement) {
            $date1904 = in_array(strtolower($properties->getAttribute('date1904')), ['1', 'true'], true);
        }

        $sheets = [];
        foreach ((new \DOMXPath($workbook))->query('//*[local-name()="sheet"]') ?: [] as $sheetNode) {
            $relationId = $sheetNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            if ($relationId === '') $relationId = $sheetNode->getAttribute('r:id');
            $target = $relationTargets[$relationId] ?? '';
            if ($target === '' || !isset($entries[$target])) continue;
            $sheets[] = [
                'name' => mb_substr($sheetNode->getAttribute('name'), 0, 100),
                'cells' => self::sheetCells(self::entry($archive, $entries, $target), $sharedStrings),
            ];
        }
        if (!$sheets) throw new DomainException('Excel内のシートを読み取れませんでした。');

        $rows = [];
        foreach ($sheets as $sheet) {
            if ($selectedSheet !== null && $sheet['name'] !== $selectedSheet) continue;
            $rows = array_merge($rows, self::calendarRows($sheet['name'], $sheet['cells'], $date1904));
        }
        if (!$rows) {
            throw new DomainException($selectedSheet !== null
                ? "「{$selectedSheet}」シートのAP～AV列に取り込み対象の予定がありません。"
                : 'AP～AV列に取り込み対象の予定がありません。');
        }
        usort($rows, static fn(array $a, array $b): int => [$a['start_date'], $a['event_type'], $a['title']] <=> [$b['start_date'], $b['event_type'], $b['title']]);
        return $rows;
    }

    private static function calendarRows(string $sheetName, array $cells, bool $date1904): array
    {
        $rows = [];
        $companyEventRow = self::LAST_DATA_ROW + 1;
        for ($row = 4; $row <= self::LAST_DATA_ROW; $row++) {
            $au = trim((string)($cells['AU' . $row] ?? ''));
            if ($au !== '' && str_contains($au, '会社行事')) {
                $companyEventRow = $row;
                break;
            }
        }

        for ($row = self::FIRST_DATA_ROW; $row <= self::LAST_DATA_ROW; $row++) {
            $otherDate = self::excelDate($cells['AP' . $row] ?? null, $date1904);
            $otherTitle = trim((string)($cells['AQ' . $row] ?? ''));
            if ($otherDate !== null || $otherTitle !== '') {
                $errors = [];
                if ($otherDate === null) $errors[] = '日付を読み取れません';
                if ($otherTitle === '') $errors[] = '予定名がありません';
                $rows[] = self::row($sheetName, 'AP' . $row, 'company_holiday', $otherTitle ?: '会社休日', $otherDate, $errors);
            }

            $meetingRaw = $cells['AS' . $row] ?? null;
            $meetingDate = self::excelDate($meetingRaw, $date1904);
            if ($meetingDate !== null) {
                $rows[] = self::row($sheetName, 'AS' . $row, 'all_hands', '全体定例会議', $meetingDate, []);
            } elseif ($meetingRaw !== null && trim((string)$meetingRaw) !== '') {
                $rows[] = self::row($sheetName, 'AS' . $row, 'all_hands', '全体定例会議', null, ['日付を読み取れません']);
            }

            $eventRaw = $cells['AU' . $row] ?? null;
            $eventDate = self::excelDate($eventRaw, $date1904);
            $eventTitle = trim((string)($cells['AV' . $row] ?? ''));
            if ($eventDate !== null) {
                $isCompanyEvent = $row > $companyEventRow;
                $errors = [];
                if ($isCompanyEvent && $eventTitle === '') $errors[] = '会社行事の予定名がありません';
                $rows[] = self::row(
                    $sheetName,
                    'AU' . $row,
                    $isCompanyEvent ? 'other' : 'recommended_leave',
                    $isCompanyEvent ? ($eventTitle ?: '会社行事') : ($eventTitle ?: '一斉有給消化日'),
                    $eventDate,
                    $errors
                );
            } elseif ($eventTitle !== '') {
                $rows[] = self::row($sheetName, 'AU' . $row, 'other', $eventTitle, null, ['日付を読み取れません']);
            }
        }
        return $rows;
    }

    private static function row(string $sheet, string $cell, string $type, string $title, ?string $date, array $errors): array
    {
        if (mb_strlen($title) > 100) $errors[] = '予定名は100文字以内にしてください';
        return [
            'source_sheet' => $sheet,
            'source_cell' => $cell,
            'event_type' => $type,
            'title' => $title,
            'start_date' => $date ?? '',
            'end_date' => $date ?? '',
            'errors' => $errors,
            'duplicate' => false,
        ];
    }

    private static function excelDate(mixed $value, bool $date1904): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) {
            $serial = (int)floor((float)$value);
            if ($serial < 1 || $serial > 100000) return null;
            $base = new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30');
            $date = $base->modify('+' . $serial . ' days');
        } else {
            $text = trim((string)$value);
            $date = null;
            foreach (['!Y-m-d', '!Y/m/d', '!n/j/Y', '!n/j/y'] as $format) {
                $candidate = DateTimeImmutable::createFromFormat($format, $text);
                $issues = DateTimeImmutable::getLastErrors();
                if ($candidate && ($issues === false || ($issues['warning_count'] === 0 && $issues['error_count'] === 0))) {
                    $date = $candidate;
                    break;
                }
            }
            if (!$date) return null;
        }
        $year = (int)$date->format('Y');
        return $year >= 2000 && $year <= 2100 ? $date->format('Y-m-d') : null;
    }

    private static function sheetCells(string $xml, array $sharedStrings): array
    {
        $document = self::xml($xml);
        $xpath = new \DOMXPath($document);
        $cells = [];
        foreach ($xpath->query('//*[local-name()="c"]') ?: [] as $cell) {
            $reference = strtoupper($cell->getAttribute('r'));
            if (!preg_match('/^(AP|AQ|AS|AU|AV)(\d+)$/', $reference, $match)) continue;
            $row = (int)$match[2];
            if ($row < 4 || $row > self::LAST_DATA_ROW) continue;
            $type = $cell->getAttribute('t');
            if ($type === 'inlineStr') {
                $text = '';
                foreach ($xpath->query('.//*[local-name()="t" and not(ancestor::*[local-name()="rPh"])]', $cell) ?: [] as $node) $text .= $node->textContent;
                $cells[$reference] = $text;
                continue;
            }
            $valueNode = $xpath->query('./*[local-name()="v"]', $cell)->item(0);
            if (!$valueNode) continue;
            $value = $valueNode->textContent;
            $cells[$reference] = $type === 's' ? ($sharedStrings[(int)$value] ?? '') : $value;
        }
        return $cells;
    }

    private static function sharedStrings(string $xml): array
    {
        $document = self::xml($xml);
        $xpath = new \DOMXPath($document);
        $strings = [];
        foreach ($xpath->query('//*[local-name()="si"]') ?: [] as $item) {
            $text = '';
            foreach ($xpath->query('.//*[local-name()="t" and not(ancestor::*[local-name()="rPh"])]', $item) ?: [] as $node) $text .= $node->textContent;
            $strings[] = $text;
        }
        return $strings;
    }

    private static function xml(string $xml): \DOMDocument
    {
        if (strlen($xml) > self::MAX_ENTRY_BYTES) throw new DomainException('Excel内のデータが大きすぎます。');
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) throw new DomainException('Excel内のXMLを解析できませんでした。');
        return $document;
    }

    private static function normalizeWorksheetPath(string $target): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', ltrim($target, '/'))) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') array_pop($parts); else $parts[] = $part;
        }
        $path = implode('/', $parts);
        return str_starts_with($path, 'xl/') ? $path : 'xl/' . $path;
    }

    private static function centralDirectory(string $archive): array
    {
        $eocd = strrpos(substr($archive, max(0, strlen($archive) - 65557)), "PK\x05\x06");
        if ($eocd === false) throw new DomainException('ExcelファイルのZIP構造を確認できません。');
        $eocd += max(0, strlen($archive) - 65557);
        $header = unpack('vdisk/vcdDisk/ventriesDisk/ventries/Vsize/Voffset/vcomment', substr($archive, $eocd + 4, 18));
        if (!$header || $header['disk'] !== 0 || $header['cdDisk'] !== 0 || $header['entries'] > 2000) {
            throw new DomainException('対応していないExcelファイルです。');
        }
        $entries = []; $offset = (int)$header['offset'];
        for ($index = 0; $index < (int)$header['entries']; $index++) {
            if (substr($archive, $offset, 4) !== "PK\x01\x02") throw new DomainException('ExcelファイルのZIP一覧が壊れています。');
            $data = unpack('vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Vlocal', substr($archive, $offset + 8, 38));
            if (!$data) throw new DomainException('ExcelファイルのZIP一覧を解析できません。');
            $name = str_replace('\\', '/', substr($archive, $offset + 46, (int)$data['name']));
            if (($data['flags'] & 1) !== 0 || $data['uncompressed'] > self::MAX_ENTRY_BYTES) throw new DomainException('暗号化または大容量のExcelには対応していません。');
            $entries[$name] = $data;
            $offset += 46 + (int)$data['name'] + (int)$data['extra'] + (int)$data['comment'];
        }
        return $entries;
    }

    private static function entry(string $archive, array $entries, string $name): string
    {
        $meta = $entries[$name] ?? null;
        if (!$meta) throw new DomainException('Excel内の必要なファイルが見つかりません。');
        $offset = (int)$meta['local'];
        if (substr($archive, $offset, 4) !== "PK\x03\x04") throw new DomainException('Excel内のデータが壊れています。');
        $local = unpack('vname/vextra', substr($archive, $offset + 26, 4));
        $compressed = substr($archive, $offset + 30 + (int)$local['name'] + (int)$local['extra'], (int)$meta['compressed']);
        if ((int)$meta['method'] === 0) $value = $compressed;
        elseif ((int)$meta['method'] === 8) $value = gzinflate($compressed);
        else throw new DomainException('対応していない圧縮形式のExcelです。');
        if ($value === false || strlen($value) !== (int)$meta['uncompressed']) throw new DomainException('Excel内の圧縮データを展開できません。');
        return $value;
    }
}
