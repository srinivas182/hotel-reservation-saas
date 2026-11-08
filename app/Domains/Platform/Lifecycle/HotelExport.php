<?php

declare(strict_types=1);

namespace App\Domains\Platform\Lifecycle;

use App\Domains\Tenancy\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * One ZIP with all of a hotel's data: one JSON and one CSV file per table,
 * plus its uploaded files. Encrypted columns are exported as stored (the
 * hotel's guests' sensitive notes stay protected); secrets are left out.
 */
final class HotelExport
{
    public function build(Hotel $hotel): string
    {
        $dir = storage_path('app/private/exports/'.$hotel->id);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        $path = $dir.'/export-'.now()->format('Ymd-His').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the export file.');
        }

        $zip->addFromString('hotel.json', (string) json_encode($hotel->only(['id', 'name', 'legal_name', 'country_code', 'currency', 'timezone', 'default_locale', 'created_at']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        foreach (HotelTables::all() as $table) {
            if (in_array($table, HotelTables::NOT_EXPORTED, true)) {
                continue;
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = DB::table($table)->where('hotel_id', $hotel->id)->get()->map(fn ($r): array => (array) $r)->values()->all();
            if ($rows === []) {
                continue;
            }

            $rows = array_map(fn (array $row): array => array_map(fn ($v) => self::readable($v), $row), $rows);
            $zip->addFromString("data/{$table}.json", (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $zip->addFromString("data/{$table}.csv", $this->csv($rows));
        }

        $disk = Storage::disk('local');
        foreach (HotelTables::folders($hotel->id) as $folder) {
            foreach ($disk->allFiles($folder) as $file) {
                $zip->addFromString('files/'.$file, (string) $disk->get($file));
            }
        }

        $zip->addFromString('README.txt', "Export of {$hotel->name}, ".now()->toIso8601String().".\nOne JSON and one CSV file per table in data/, uploaded files in files/.\n");
        $zip->close();

        return $path;
    }

    /**
     * Encrypted columns (guest notes, messages…) are exported readable: the
     * export is for the hotel itself. Secrets tables are never exported.
     */
    private static function readable(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, 'eyJpdiI')) {
            try {
                return \Illuminate\Support\Facades\Crypt::decryptString($value);
            } catch (\Throwable) {
                return $value;
            }
        }

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($v): string => is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v), array_values($row)));
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
