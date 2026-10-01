<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A table, as a CSV file a spreadsheet opens without being asked anything.
 *
 * Two things are done for Excel's sake. The file starts with a byte-order
 * mark, without which a dish named in Hindi arrives as question marks. And a
 * cell of text that begins like a formula is given a leading apostrophe:
 * a supplier named "=HYPERLINK(...)" should be read, not run. Numbers are
 * left as numbers, minus sign and all, so the column can be added up.
 */
final class Csv
{
    /**
     * @param  list<string>  $headings
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    public static function download(string $filename, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows) {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headings, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::cell(...), $row), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A file name from its parts, with nothing in it a file system minds. */
    public static function filename(string ...$parts): string
    {
        $name = implode('-', array_filter(array_map(
            fn (string $part) => trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $part), '-'),
            $parts,
        )));

        return strtolower($name).'.csv';
    }

    public static function cell(string|int|float|null $value): string
    {
        $value = (string) ($value ?? '');

        if ($value !== '' && ! is_numeric($value) && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'".$value;
        }

        return $value;
    }
}
