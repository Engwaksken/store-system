<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Reads an uploaded CSV (UTF-8, optional BOM, header row) into associative rows keyed by lower-case column name. */
final class CsvImport
{
    /**
     * @param  array<int, string>  $required
     * @return array{0: array<int, array<string, string>>, 1: ?string} [rows keyed by spreadsheet line number, error]
     */
    public static function read(UploadedFile $file, array $required): array
    {
        $fh = fopen($file->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($fh) ?: []);
        $missing = array_diff($required, $header);
        if ($missing) {
            fclose($fh);

            return [[], 'The first row must contain the columns: '.implode(', ', $required).'. Missing: '.implode(', ', $missing).'.'];
        }
        $rows = [];
        $line = 1;
        while (($r = fgetcsv($fh)) !== false) {
            $line++;
            if (count(array_filter($r, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank line
            }
            $row = [];
            foreach ($header as $i => $h) {
                $row[$h] = trim((string) ($r[$i] ?? ''));
            }
            $rows[$line] = $row;
            if (count($rows) > 5000) {
                fclose($fh);

                return [[], 'At most 5,000 rows per file.'];
            }
        }
        fclose($fh);

        return [$rows, $rows ? null : 'The file has no data rows.'];
    }

    /** @param array<int, array<int, string>> $examples */
    public static function template(string $filename, array $columns, array $examples = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $examples) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);
            foreach ($examples as $e) {
                fputcsv($out, $e);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function number(string $v): ?string
    {
        $v = str_replace([',', ' '], '', $v);

        return is_numeric($v) && (float) $v >= 0 ? $v : null;
    }
}
