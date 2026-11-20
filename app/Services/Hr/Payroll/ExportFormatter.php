<?php

namespace App\Services\Hr\Payroll;

use DateTimeImmutable;

/**
 * Turns rows into a delimited or fixed-width file from a LAYOUT THE ACCOUNTANT DEFINES (Guide 5.6: the bank format must be agreed with the bank, the declaration formats with RRA/RSSB).
 * Pure: no database, no built-in bank or statutory format. Strict by design: a value that does not fit its width, a cell that would break the delimiter, an unknown placeholder or an encoding
 * that would lose a character raises ExportFormatError instead of producing a file the bank might misread.
 *
 * profile: layout delimited|fixed_width, delimiter, quote, line_ending LF|CRLF, encoding UTF-8|ISO-8859-1, decimal_separator, date_format (a PHP date format), include_header, header_template, trailer_template,
 *          columns[] = [header, source, format text|amount|integer_minor|integer|date, decimals, width, align left|right, pad_char, truncate, constant]
 * rows: list of [field => string|null]. vars: values for {placeholders} in the header / trailer ({count} is added automatically).
 */
final class ExportFormatter
{
    public static function render(array $profile, array $rows, array $vars = []): string
    {
        $layout = $profile['layout'] ?? 'delimited';
        $eol = ($profile['line_ending'] ?? 'LF') === 'CRLF' ? "\r\n" : "\n";
        $cols = $profile['columns'] ?? [];
        if ($cols === []) {
            throw new ExportFormatError('no_columns');
        }
        $vars += ['count' => (string) count($rows)];
        $lines = [];
        if (!empty($profile['header_template'])) {
            foreach (explode("\n", self::template($profile['header_template'], $vars)) as $l) {
                $lines[] = $l;
            }
        }
        if ($layout === 'delimited' && !empty($profile['include_header'])) {
            $lines[] = self::joinDelimited(array_map(fn ($c) => ['text' => (string) $c['header'], 'plain' => true], $cols), $profile);
        }
        foreach (array_values($rows) as $i => $row) {
            $cells = [];
            foreach ($cols as $c) {
                $cells[] = ['text' => self::cell($c, $row, $i + 1, $profile), 'column' => $c];
            }
            $lines[] = $layout === 'fixed_width' ? self::joinFixed($cells) : self::joinDelimited($cells, $profile);
        }
        if (!empty($profile['trailer_template'])) {
            foreach (explode("\n", self::template($profile['trailer_template'], $vars)) as $l) {
                $lines[] = $l;
            }
        }
        return self::encode(implode($eol, $lines) . $eol, $profile['encoding'] ?? 'UTF-8');
    }

    /** One formatted value (before padding / quoting). */
    public static function cell(array $c, array $row, int $rowNumber, array $profile): string
    {
        $src = $c['source'] ?? '';
        $raw = match ($src) {
            'constant' => (string) ($c['constant'] ?? ''),
            'row_number' => (string) $rowNumber,
            default => $row[$src] ?? '',
        };
        $raw = (string) $raw;
        $dec = (int) ($c['decimals'] ?? 2);
        $sep = $profile['decimal_separator'] ?? '.';
        switch ($c['format'] ?? 'text') {
            case 'amount':
                if ($raw === '') return '';
                self::numeric($raw, $src);
                return str_replace('.', $sep, PayRound::apply($raw, PayRound::NEAREST, $dec));
            case 'integer_minor':
                if ($raw === '') return '';
                self::numeric($raw, $src);
                return bcmul(PayRound::apply($raw, PayRound::NEAREST, $dec), $dec === 0 ? '1' : '1' . str_repeat('0', $dec), 0);
            case 'integer':
                if ($raw === '') return '';
                self::numeric($raw, $src);
                return bcadd($raw, '0', 0);
            case 'date':
                if ($raw === '') return '';
                return (new DateTimeImmutable($raw))->format($profile['date_format'] ?? 'Y-m-d');
            default:
                return $raw;
        }
    }

    private static function numeric(string $v, string $src): void
    {
        if (!is_numeric($v)) {
            throw new ExportFormatError('not_numeric', "$src=$v");
        }
    }

    private static function joinDelimited(array $cells, array $profile): string
    {
        $d = $profile['delimiter'] ?? ';';
        $q = $profile['quote'] ?? '"';
        $out = [];
        foreach ($cells as $c) {
            $t = $c['text'];
            $isText = !empty($c['plain']) || (($c['column']['format'] ?? 'text') === 'text');
            if ($isText && $t !== '' && in_array($t[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($t)) {
                $t = "'" . $t;                                      // a spreadsheet would run this as a formula
            }
            $needs = ($d !== '' && str_contains($t, $d)) || ($q !== '' && str_contains($t, $q)) || str_contains($t, "\n") || str_contains($t, "\r");
            if ($needs) {
                if ($q === '') {
                    throw new ExportFormatError('delimiter_in_value', $t);
                }
                $t = $q . str_replace($q, $q . $q, $t) . $q;
            }
            $out[] = $t;
        }
        return implode($d, $out);
    }

    private static function joinFixed(array $cells): string
    {
        $out = '';
        foreach ($cells as $c) {
            $col = $c['column'];
            $w = (int) ($col['width'] ?? 0);
            if ($w <= 0) {
                throw new ExportFormatError('width_required', (string) ($col['header'] ?? $col['source'] ?? ''));
            }
            $t = $c['text'];
            $len = mb_strlen($t);
            if ($len > $w) {
                if (empty($col['truncate']) || ($col['format'] ?? 'text') !== 'text') {
                    throw new ExportFormatError('overflow', ($col['header'] ?? $col['source']) . ": '$t' does not fit $w");
                }
                $t = mb_substr($t, 0, $w);
            }
            $pad = ($col['pad_char'] ?? ' ') === '' ? ' ' : mb_substr((string) ($col['pad_char'] ?? ' '), 0, 1);
            $fill = str_repeat($pad, $w - mb_strlen($t));
            $out .= ($col['align'] ?? 'left') === 'right' ? $fill . $t : $t . $fill;
        }
        return $out;
    }

    private static function template(string $tpl, array $vars): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) {
            if (!array_key_exists($m[1], $vars)) {
                throw new ExportFormatError('unknown_placeholder', $m[1]);
            }
            return (string) $vars[$m[1]];
        }, $tpl);
    }

    private static function encode(string $s, string $encoding): string
    {
        if (strtoupper($encoding) === 'UTF-8') {
            return $s;
        }
        $out = mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        if (mb_convert_encoding($out, 'UTF-8', 'ISO-8859-1') !== $s) {
            throw new ExportFormatError('encoding', 'a character cannot be represented in ' . $encoding);
        }
        return $out;
    }
}
