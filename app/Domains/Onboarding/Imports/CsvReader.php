<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Reads a small CSV (comma or semicolon, UTF-8 with or without BOM, as
 * Excel saves it in French) into rows keyed by header.
 */
final class CsvReader
{
    public const MAX_ROWS = 1000;

    /**
     * @param  list<string>  $required
     * @return list<array<string, string>>
     */
    public static function read(UploadedFile $file, array $required): array
    {
        $content = (string) file_get_contents($file->getRealPath());
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];
        $first = (string) array_shift($lines);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $headers = array_map(fn (?string $h): string => strtolower(trim((string) $h)), str_getcsv($first, $delimiter, '"', ''));
        $missing = array_diff($required, $headers);

        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => __('Missing columns: :columns', ['columns' => implode(', ', $missing)])]);
        }

        if (count($lines) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => __('Import at most :max rows at a time.', ['max' => self::MAX_ROWS])]);
        }

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, $delimiter, '"', '');
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = trim((string) ($values[$i] ?? ''));
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
