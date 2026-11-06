<?php

namespace App\Support;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Reads the rows of a CSV or XLSX file (e.g. a Matrixify export) as arrays keyed by the header row.
 * XLSX is read with ZipArchive and XMLReader, one row at a time, with no spreadsheet library.
 */
class SheetReader
{
    /** @return Generator<int, array<string, string>> */
    public static function rows(string $path, ?string $sheet = null): Generator
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        return str_ends_with(mb_strtolower($path), '.xlsx') ? self::xlsx($path, $sheet) : self::csv($path);
    }

    /** @return Generator<int, array<string, string>> */
    private static function csv(string $path): Generator
    {
        $h = fopen($path, 'r');
        $header = null;
        $n = 0;
        while (($row = fgetcsv($h, 0, ',', '"', '')) !== false) {
            if ($header === null) {
                $header = array_map(fn ($c) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c)), $row);

                continue;
            }
            if ($row === [null]) {
                continue;
            }
            yield $n++ => self::combine($header, $row);
        }
        fclose($h);
    }

    /** @return Generator<int, array<string, string>> */
    private static function xlsx(string $path, ?string $sheet): Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Not a readable XLSX file: {$path}");
        }
        $shared = [];
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml !== false) {
            $r = new XMLReader;
            $r->XML($xml);
            $current = null;
            while ($r->read()) {
                if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'si') {
                    $current = '';
                } elseif ($r->nodeType === XMLReader::ELEMENT && $r->name === 't' && $current !== null) {
                    $current .= $r->readString();
                } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'si') {
                    $shared[] = $current;
                    $current = null;
                }
            }
        }
        $target = self::sheetPath($zip, $sheet);
        $data = $zip->getFromName($target);
        $zip->close();
        if ($data === false) {
            throw new RuntimeException("Worksheet not found in {$path}");
        }

        $r = new XMLReader;
        $r->XML($data);
        $header = null;
        $n = 0;
        $row = [];
        $col = 0;
        $type = null;
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'row') {
                $row = [];
                $col = 0;
            } elseif ($r->nodeType === XMLReader::ELEMENT && $r->name === 'c') {
                $ref = (string) $r->getAttribute('r');
                $col = $ref !== '' ? self::column($ref) : $col + 1;
                $type = $r->getAttribute('t');
                $row[$col] = '';
            } elseif ($r->nodeType === XMLReader::ELEMENT && ($r->name === 'v' || ($r->name === 't' && $type === 'inlineStr'))) {
                $v = $r->readString();
                $row[$col] = $type === 's' ? (string) ($shared[(int) $v] ?? '') : $v;
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'row') {
                if ($row === []) {
                    continue;
                }
                $max = max(array_keys($row));
                $flat = [];
                for ($i = 1; $i <= $max; $i++) {
                    $flat[] = $row[$i] ?? '';
                }
                if ($header === null) {
                    $header = array_map('trim', $flat);

                    continue;
                }
                yield $n++ => self::combine($header, $flat);
            }
        }
    }

    private static function sheetPath(ZipArchive $zip, ?string $sheet): string
    {
        $book = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $rels = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        if (! $book || ! $rels) {
            return 'xl/worksheets/sheet1.xml';
        }
        $targets = [];
        foreach ($rels->Relationship as $rel) {
            $targets[(string) $rel['Id']] = (string) $rel['Target'];
        }
        $book->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $first = null;
        foreach ($book->xpath('//m:sheets/m:sheet') ?: [] as $s) {
            $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $t = $targets[$rid] ?? null;
            if ($t === null) {
                continue;
            }
            $t = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/'.$t;
            $first ??= $t;
            if ($sheet !== null && strcasecmp((string) $s['name'], $sheet) === 0) {
                return $t;
            }
        }

        return $first ?? 'xl/worksheets/sheet1.xml';
    }

    /** "C12" -> 3 */
    private static function column(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', strtoupper($ref));
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n;
    }

    /** @param list<string> $header @param array<int, mixed> $row @return array<string, string> */
    private static function combine(array $header, array $row): array
    {
        $out = [];
        foreach ($header as $i => $name) {
            if ($name !== '' && ! isset($out[$name])) {
                $out[$name] = trim((string) ($row[$i] ?? ''));
            }
        }

        return $out;
    }
}
