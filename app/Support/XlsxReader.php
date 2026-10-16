<?php

namespace App\Support;

use ZipArchive;

/**
 * Minimal, dependency-free reader for the first worksheet of an .xlsx file
 * (the counterpart of XlsxWriter), used by the salary and attendance imports
 * so people can upload the Excel template directly instead of saving a CSV.
 * Cells come back as strings; Excel dates/times stay as their serial numbers
 * (ImportService understands those).
 */
class XlsxReader
{
    /**
     * @return array<int, array<int, string>> rows of cell values (column order, gaps filled with '')
     */
    public static function rows(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('The Excel file could not be opened. Save it as .xlsx (Excel Workbook) or .csv and try again.');

        try {
            $shared = self::sharedStrings($zip);
            $sheetXml = $zip->getFromName(self::firstSheetPath($zip));
            if ($sheetXml === false) throw new \RuntimeException('The Excel file has no worksheet.');

            $sheet = simplexml_load_string($sheetXml, \SimpleXMLElement::class, LIBXML_NONET);
            if ($sheet === false) throw new \RuntimeException('The Excel worksheet could not be read.');
            $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            $rows = [];
            foreach ($sheet->xpath('//m:sheetData/m:row') ?: [] as $row) {
                $cells = [];
                $next = 0;
                foreach ($row->c as $cell) {
                    $index = isset($cell['r']) ? self::columnIndex((string) $cell['r']) : $next;
                    $type = (string) ($cell['t'] ?? 'n');
                    $value = match ($type) {
                        's' => $shared[(int) $cell->v] ?? '',
                        'inlineStr' => self::text($cell->is),
                        'b' => ((string) $cell->v) === '1' ? 'TRUE' : 'FALSE',
                        default => (string) $cell->v,
                    };
                    $cells[$index] = trim($value);
                    $next = $index + 1;
                }
                if (! $cells) continue;
                $filled = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $i => $v) $filled[$i] = $v;
                $rows[] = $filled;
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /**
     * Every worksheet in workbook order: [['name' => sheet name, 'rows' => [row number => [column index => value]]], …].
     * Row numbers are the Excel row numbers (so notes written under a row can be matched to it).
     */
    public static function sheets(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('The Excel file could not be opened. Save it as .xlsx (Excel Workbook) or .csv and try again.');
        try {
            $shared = self::sharedStrings($zip);
            $wb = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'), \SimpleXMLElement::class, LIBXML_NONET);
            $rel = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'), \SimpleXMLElement::class, LIBXML_NONET);
            $targets = [];
            foreach ($rel?->Relationship ?? [] as $r) {
                $target = ltrim((string) $r['Target'], '/');
                $targets[(string) $r['Id']] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
            $sheets = [];
            foreach ($wb?->sheets?->sheet ?? [] as $sheet) {
                $rid = (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
                $xml = isset($targets[$rid]) ? $zip->getFromName($targets[$rid]) : false;
                if ($xml === false) continue;
                $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
                if ($doc === false) continue;
                $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $rows = [];
                foreach ($doc->xpath('//m:sheetData/m:row') ?: [] as $row) {
                    $cells = [];
                    foreach ($row->c as $cell) {
                        $type = (string) ($cell['t'] ?? 'n');
                        $value = trim(match ($type) {
                            's' => $shared[(int) $cell->v] ?? '',
                            'inlineStr' => self::text($cell->is),
                            'b' => ((string) $cell->v) === '1' ? 'TRUE' : 'FALSE',
                            default => (string) $cell->v,
                        });
                        if ($value !== '') $cells[self::columnIndex((string) $cell['r'])] = $value;
                    }
                    if ($cells) $rows[(int) $row['r']] = $cells;
                }
                $sheets[] = ['name' => (string) $sheet['name'], 'rows' => $rows];
            }

            return $sheets;
        } finally {
            $zip->close();
        }
    }

    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        if ($doc === false) return [];

        $strings = [];
        foreach ($doc->si as $item) $strings[] = self::text($item);

        return $strings;
    }

    /** Text of an <si>/<is> node, including rich-text runs. */
    private static function text(?\SimpleXMLElement $node): string
    {
        if (! $node) return '';
        if (isset($node->t)) return (string) $node->t;
        $text = '';
        foreach ($node->r as $run) $text .= (string) $run->t;

        return $text;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook && $rels) {
            $wb = simplexml_load_string($workbook, \SimpleXMLElement::class, LIBXML_NONET);
            $rel = simplexml_load_string($rels, \SimpleXMLElement::class, LIBXML_NONET);
            $sheet = $wb?->sheets?->sheet[0] ?? null;
            $rid = $sheet ? (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '') : '';
            foreach ($rel?->Relationship ?? [] as $r) {
                if ((string) $r['Id'] === $rid) {
                    $target = ltrim((string) $r['Target'], '/');

                    return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function columnIndex(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference));
        $index = 0;
        foreach (str_split($letters) as $letter) $index = $index * 26 + (ord($letter) - 64);

        return max(0, $index - 1);
    }
}
