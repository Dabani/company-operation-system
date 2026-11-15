<?php

namespace App\Services\Hr\Attendance\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads an uploaded attendance file into plain rows, whatever its format: Excel (.xlsx, .xlsm, .xls), OpenDocument (.ods), an HTML table saved with an .xls name
 * (some devices do this), or text (CSV / semicolon / tab / pipe separated, UTF-8 or Windows-1252). The format is detected from the file itself, not its name.
 * Excel files are read with PhpSpreadsheet, which the application already ships with Laravel Excel.
 */
class SpreadsheetReader
{
    public const MAX_ROWS = 20000;
    private const MAX_UNZIPPED = 157286400;                       // 150 MB: protects against zip bombs

    /** @return array{rows:array<int,array<int,mixed>>,sheets:array<int,string>,sheet:?string,type:string} */
    public function read(string $path, ?string $sheet = null): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);
        if ($head === '') {
            throw new ImportFileException('import_empty');
        }
        if (str_starts_with($head, "PK") && $this->isZip($path)) {                  // a text file that merely starts with "PK" is not a workbook
            return $this->spreadsheet($path, $sheet, 'xlsx', true);
        }
        if (str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            return $this->spreadsheet($path, $sheet, 'xls', false);
        }
        $text = (string) file_get_contents($path);
        if (preg_match('/<\s*(table|html)\b/i', substr($text, 0, 4096))) {
            return $this->spreadsheet($path, $sheet, 'html', false);
        }
        return $this->csv($text);
    }

    private function isZip(string $path): bool
    {
        if (!class_exists(\ZipArchive::class)) {
            return true;
        }
        $z = new \ZipArchive();
        $ok = $z->open($path) === true;
        $ok && $z->close();
        return $ok;
    }

    private function spreadsheet(string $path, ?string $sheet, string $type, bool $zip): array
    {
        if (!class_exists(IOFactory::class)) {
            throw new ImportFileException('excel_unavailable');
        }
        if ($zip && class_exists(\ZipArchive::class)) {
            $z = new \ZipArchive();
            if ($z->open($path) !== true) {
                throw new ImportFileException('excel_unreadable');
            }
            $size = 0;
            for ($i = 0; $i < $z->numFiles; $i++) {
                $size += (int) ($z->statIndex($i)['size'] ?? 0);
            }
            $z->close();
            if ($size > self::MAX_UNZIPPED) {
                throw new ImportFileException('excel_unreadable');
            }
        }
        try {
            $reader = IOFactory::createReaderForFile($path);
            if (method_exists($reader, 'setReadDataOnly')) {
                $reader->setReadDataOnly(true);                  // raw values: dates arrive as day numbers, times as fractions of a day
            }
            $book = $reader->load($path);
            $names = $book->getSheetNames();
            if ($sheet !== null && trim($sheet) !== '' && !in_array($sheet, $names, true)) {
                throw new ImportFileException('sheet_not_found', ['sheet' => $sheet]);
            }
            $ws = $sheet !== null && trim($sheet) !== '' ? $book->getSheetByName($sheet) : $book->getSheet(0);
            $rows = $ws->toArray(null, false, false, false);
            $used = $ws->getTitle();
            $book->disconnectWorksheets();
        } catch (ImportFileException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ImportFileException('excel_unreadable');
        }
        return ['rows' => $this->trim($rows), 'sheets' => $names, 'sheet' => $used, 'type' => $type];
    }

    private function csv(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $lines = array_slice(array_values(array_filter(preg_split('/\r\n|\n|\r/', $text, 12), fn ($l) => trim($l) !== '')), 0, 5);
        $best = ',';
        $top = -1;
        foreach ([',', ';', "\t", '|'] as $d) {
            $n = 0;
            foreach ($lines as $l) {
                $n += substr_count($l, $d);
            }
            if ($n > $top) { [$best, $top] = [$d, $n]; }
        }
        $h = fopen('php://temp', 'r+');
        fwrite($h, $text);
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, 0, $best)) !== false) {
            $rows[] = $r === [null] ? [] : $r;
            if (count($rows) > self::MAX_ROWS + 60) {
                break;
            }
        }
        fclose($h);
        return ['rows' => $this->trim($rows), 'sheets' => [], 'sheet' => null, 'type' => 'csv'];
    }

    /** Drops empty rows at the end (spreadsheets often carry thousands of formatted blank rows) and enforces the row limit. */
    private function trim(array $rows): array
    {
        while ($rows && !array_filter(end($rows), fn ($c) => $c !== null && trim((string) $c) !== '')) {
            array_pop($rows);
        }
        if (count($rows) > self::MAX_ROWS + 60) {
            throw new ImportFileException('import_too_many', ['max' => self::MAX_ROWS]);
        }
        return $rows;
    }
}
