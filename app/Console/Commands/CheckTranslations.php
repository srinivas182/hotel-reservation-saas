<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Fails when translations are incomplete:
 * - a string used in code (__('...') in PHP and Blade, t('...') in React)
 *   is missing from lang/en.json, or
 * - any other language file is missing a key from en.json or has a key
 *   that en.json no longer contains.
 *
 * Runs in CI. Use --sync to add missing keys to en.json (value = key).
 */
#[Signature('translations:check {--sync : Add strings found in code to lang/en.json}')]
#[Description('Check that every language file covers every string used in the code')]
final class CheckTranslations extends Command
{
    private const PHP_PATTERN = '/__\(\s*\'((?:[^\'\\\\]|\\\\.)+)\'/';

    private const JS_PATTERN = '/\bt\(\s*(?:\'((?:[^\'\\\\]|\\\\.)+)\'|"((?:[^"\\\\]|\\\\.)+)")/';

    public function handle(): int
    {
        $used = $this->usedKeys();
        $enPath = lang_path('en.json');
        $en = $this->read($enPath);

        $missingInEn = array_values(array_diff($used, array_keys($en)));

        if ($this->option('sync') && $missingInEn !== []) {
            foreach ($missingInEn as $key) {
                $en[$key] = $key;
            }
            ksort($en, SORT_STRING | SORT_FLAG_CASE);
            File::put($enPath, json_encode($en, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $this->components->info(count($missingInEn).' key(s) added to lang/en.json.');
            $missingInEn = [];
        }

        $failed = false;

        if ($missingInEn !== []) {
            $failed = true;
            $this->components->error('Strings used in code but missing from lang/en.json:');
            $this->listKeys($missingInEn);
        }

        foreach (File::glob(lang_path('*.json')) as $path) {
            if ($path === $enPath) {
                continue;
            }

            $locale = basename($path, '.json');
            $messages = $this->read($path);
            $missing = array_values(array_diff(array_keys($en), array_keys($messages)));
            $extra = array_values(array_diff(array_keys($messages), array_keys($en)));
            $empty = array_keys(array_filter($messages, fn ($value): bool => ! is_string($value) || trim($value) === ''));

            foreach (['missing' => $missing, 'not in en.json' => $extra, 'empty' => $empty] as $problem => $keys) {
                if ($keys !== []) {
                    $failed = true;
                    $this->components->error("lang/{$locale}.json: ".count($keys)." key(s) {$problem}:");
                    $this->listKeys($keys);
                }
            }
        }

        if ($failed) {
            return self::FAILURE;
        }

        $this->components->info('Translations are complete ('.count($en).' strings).');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function usedKeys(): array
    {
        $keys = [];

        $phpFiles = collect([app_path(), resource_path('views'), base_path('routes'), base_path('config')])
            ->flatMap(fn (string $dir) => File::allFiles($dir))
            ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.php'));

        foreach ($phpFiles as $file) {
            preg_match_all(self::PHP_PATTERN, $file->getContents(), $matches);
            foreach ($matches[1] as $key) {
                $keys[] = stripcslashes($key);
            }
        }

        $jsFiles = collect(File::allFiles(resource_path('js')))
            ->filter(fn (SplFileInfo $file): bool => preg_match('/\.tsx?$/', $file->getFilename()) === 1)
            ->reject(fn (SplFileInfo $file): bool => str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'actions'.DIRECTORY_SEPARATOR)
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR)
                || str_contains($file->getPathname(), '__tests__'));

        foreach ($jsFiles as $file) {
            preg_match_all(self::JS_PATTERN, $file->getContents(), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $captured = array_values(array_filter(array_slice($match, 1), fn (string $value): bool => $value !== ''));
                $keys[] = stripcslashes($captured[0] ?? '');
            }
        }

        return array_values(array_unique(array_filter($keys, fn (string $key): bool => $key !== '')));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  list<string>  $keys
     */
    private function listKeys(array $keys): void
    {
        foreach (array_slice($keys, 0, 50) as $key) {
            $this->line('  - '.$key);
        }

        if (count($keys) > 50) {
            $this->line('  ... and '.(count($keys) - 50).' more');
        }
    }
}
