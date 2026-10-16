<?php

namespace App\Support;

use ZipArchive;

/**
 * Small dependency-free .xlsx builder for import templates: several sheets,
 * a styled header row, typed cells (date, time, integer, currency, decimal,
 * text), formulas, column widths, a frozen header and data validations.
 *
 * A sheet is ['name' => ..., 'columns' => [[title, style, width], ...],
 * 'rows' => [[cell, ...], ...], 'validations' => [...]] where a cell is a
 * plain value or ['v' => value, 'f' => formula, 's' => style].
 */
class XlsxWorkbook
{
    /** style name => cellXfs index (see styles()). */
    public const STYLES = ['text' => 0, 'header' => 1, 'date' => 2, 'time' => 3, 'integer' => 4, 'currency' => 5, 'decimal' => 6, 'duration' => 7, 'wrap' => 8, 'title' => 9];

    /** @param array<int, array{name: string, columns: array, rows: array, validations?: array}> $sheets */
    public static function toTempFile(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Could not create the Excel file.');

        $overrides = '';
        $sheetTags = '';
        $rels = '';
        foreach (array_values($sheets) as $i => $sheet) {
            $n = $i + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetTags .= '<sheet name="'.self::xml(mb_substr($sheet['name'], 0, 31)).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$n.'.xml', self::sheet($sheet));
        }
        $stylesId = count($sheets) + 1;

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$overrides.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetTags.'</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels
            .'<Relationship Id="rId'.$stylesId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->close();

        return $path;
    }

    private static function sheet(array $sheet): string
    {
        $columns = $sheet['columns'];
        $cols = '';
        foreach ($columns as $i => $column) $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.($column[2] ?? 16).'" customWidth="1"/>';
        $freeze = ($sheet['freeze'] ?? true) ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' : '';

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .$freeze.'<cols>'.$cols.'</cols><sheetData>';
        $xml .= '<row r="1">';
        foreach ($columns as $i => $column) $xml .= self::cell(self::letter($i).'1', ['v' => $column[0], 's' => 'header']);
        $xml .= '</row>';
        foreach (array_values($sheet['rows']) as $r => $row) {
            $rowNum = $r + 2;
            $xml .= '<row r="'.$rowNum.'">';
            foreach (array_values($row) as $i => $value) {
                $cell = is_array($value) ? $value : ['v' => $value];
                $cell['s'] ??= $columns[$i][1] ?? 'text';
                if (isset($cell['f'])) $cell['f'] = str_replace('{r}', (string) $rowNum, $cell['f']);
                $xml .= self::cell(self::letter($i).$rowNum, $cell);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if (! empty($sheet['validations'])) {
            $xml .= '<dataValidations count="'.count($sheet['validations']).'">';
            foreach ($sheet['validations'] as $v) {
                $attrs = 'type="'.$v['type'].'" allowBlank="1" showErrorMessage="1" sqref="'.$v['range'].'"'
                    .(isset($v['operator']) ? ' operator="'.$v['operator'].'"' : '')
                    .(isset($v['error']) ? ' errorTitle="Invalid value" error="'.self::xml($v['error']).'"' : '')
                    .(isset($v['prompt']) ? ' showInputMessage="1" prompt="'.self::xml($v['prompt']).'"' : '');
                $xml .= '<dataValidation '.$attrs.'><formula1>'.self::xml($v['formula1']).'</formula1>'
                    .(isset($v['formula2']) ? '<formula2>'.self::xml($v['formula2']).'</formula2>' : '').'</dataValidation>';
            }
            $xml .= '</dataValidations>';
        }

        return $xml.'</worksheet>';
    }

    private static function cell(string $ref, array $cell): string
    {
        $style = ' s="'.(self::STYLES[$cell['s'] ?? 'text'] ?? 0).'"';
        $value = $cell['v'] ?? null;
        if (isset($cell['f'])) {
            return '<c r="'.$ref.'"'.$style.'><f>'.self::xml(ltrim($cell['f'], '=')).'</f></c>';
        }
        if ($value === null || $value === '') return '<c r="'.$ref.'"'.$style.'/>';
        if (in_array($cell['s'] ?? '', ['date'], true) && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value = (int) ((strtotime($value.' 00:00:00 UTC') - strtotime('1899-12-30 00:00:00 UTC')) / 86400);
        }
        if (in_array($cell['s'] ?? '', ['time', 'duration'], true) && is_string($value) && preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) {
            $value = round(((int) $m[1] * 60 + (int) $m[2]) / 1440, 10);
        }
        if (is_int($value) || is_float($value)) return '<c r="'.$ref.'"'.$style.'><v>'.$value.'</v></c>';

        return '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.self::xml((string) $value).'</t></is></c>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="4"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/><numFmt numFmtId="165" formatCode="hh:mm"/><numFmt numFmtId="166" formatCode="0.0"/><numFmt numFmtId="167" formatCode="[h]:mm"/></numFmts>'
            .'<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="12"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F3A5F"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD0D7E2"/></left><right style="thin"><color rgb="FFD0D7E2"/></right><top style="thin"><color rgb="FFD0D7E2"/></top><bottom style="thin"><color rgb="FFD0D7E2"/></bottom><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="10">'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment wrapText="1" vertical="center" horizontal="center"/></xf>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            .'<xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            .'<xf numFmtId="4" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            .'<xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            .'<xf numFmtId="167" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    public static function letter(int $index): string
    {
        $letter = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) $letter = chr(65 + ($n - 1) % 26).$letter;

        return $letter;
    }

    private static function xml(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
