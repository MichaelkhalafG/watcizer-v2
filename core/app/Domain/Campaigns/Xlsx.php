<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * THE SMALLEST .XLSX THAT WORKS (2026-11-24, D14): the campaign list's template and its upload, with PHP's own zip and
 * XML readers - no package. Writes one sheet of strings; reads the FIRST sheet's cell values as text (shared strings,
 * inline strings, numbers), nothing else (no formulas evaluated, no styles, no other sheets).
 */
final class Xlsx
{
    /**
     * A workbook of one sheet holding these rows as text.
     *
     * @param  list<list<string>>  $rows
     */
    public static function write(array $rows, string $sheetName = 'List'): string
    {
        $esc = fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="3" width="32" customWidth="1"/></cols><sheetData>';
        foreach ($rows as $r => $row) {
            $sheet .= '<row r="'.($r + 1).'">';
            foreach ($row as $c => $value) {
                $sheet .= '<c r="'.self::column($c).($r + 1).'" t="inlineStr"><is><t xml:space="preserve">'.$esc($value).'</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="'.$esc($sheetName).'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new RuntimeException('no temporary file');
        }
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('cannot write the workbook');
        }
        foreach ($parts as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /**
     * The first sheet's rows, every cell as text, in column order (empty cells as ''). Throws on a file that is not a
     * workbook.
     *
     * @return list<list<string>>
     */
    public static function read(string $path, int $maxRows = 5001): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('not an .xlsx file');
        }
        $shared = [];
        $strings = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($strings)) {
            $x = new XMLReader;
            $x->XML($strings, null, LIBXML_NONET);
            $current = null;
            while ($x->read()) {
                if ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'si') {
                    $current = '';
                } elseif ($x->nodeType === XMLReader::ELEMENT && $x->localName === 't' && $current !== null) {
                    $current .= $x->readString();
                } elseif ($x->nodeType === XMLReader::END_ELEMENT && $x->localName === 'si') {
                    $shared[] = (string) $current;
                    $current = null;
                }
            }
        }
        $sheetPath = self::firstSheet($zip);
        $xml = $zip->getFromName($sheetPath);
        $zip->close();
        if (! is_string($xml)) {
            throw new RuntimeException('the workbook has no sheet');
        }
        $rows = [];
        $x = new XMLReader;
        $x->XML($xml, null, LIBXML_NONET);
        $row = null;
        while ($x->read()) {
            if ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'row') {
                $row = [];
            } elseif ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'c' && $row !== null) {
                $ref = (string) $x->getAttribute('r');
                $type = (string) $x->getAttribute('t');
                $index = $ref === '' ? count($row) : self::index($ref);
                $value = '';
                $cell = $x->readOuterXml();
                if ($type === 'inlineStr') {
                    preg_match_all('#<t[^>]*>(.*?)</t>#s', $cell, $m);
                    $value = html_entity_decode(implode('', $m[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                } elseif (preg_match('#<v>(.*?)</v>#s', $cell, $m) === 1) {
                    $value = html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    if ($type === 's') {
                        $value = $shared[(int) $value] ?? '';
                    } elseif ($type === '' && is_numeric($value) && preg_match('/^\d+(\.0+)?$/', $value) === 1) {
                        $value = (string) (int) $value;    // a phone typed as a number: 1012345678
                    }
                }
                $row[$index] = trim($value);
            } elseif ($x->nodeType === XMLReader::END_ELEMENT && $x->localName === 'row' && $row !== null) {
                $width = $row === [] ? 0 : max(array_keys($row)) + 1;
                $line = [];
                for ($i = 0; $i < $width; $i++) {
                    $line[] = $row[$i] ?? '';
                }
                $rows[] = $line;
                $row = null;
                if (count($rows) >= $maxRows) {
                    break;
                }
            }
        }

        return $rows;
    }

    private static function firstSheet(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (is_string($workbook) && is_string($rels) && preg_match('#<sheet\b[^>]*\br:id="([^"]+)"#', $workbook, $m) === 1
            && preg_match('#<Relationship\b[^>]*\bId="'.preg_quote($m[1], '#').'"[^>]*\bTarget="([^"]+)"#', $rels, $t) === 1) {
            $target = ltrim($t[1], '/');

            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function column(int $index): string
    {
        $s = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }

    private static function index(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?? '';
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }
}
