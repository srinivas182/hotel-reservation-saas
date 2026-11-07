<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Imports;

use App\Domains\Audit\Audit;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Rooms from a CSV: category (code or name), number, floor.
 */
final class RoomImport
{
    public const COLUMNS = ['category', 'number', 'floor'];

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{line: int, category: string, number: string, floor: string, category_id: string|null, errors: list<string>}>
     */
    public function preview(array $rows): array
    {
        $categories = RoomCategory::query()->get();
        $existing = Room::query()->pluck('number')->map(fn ($n): string => (string) $n)->all();
        $seen = [];
        $result = [];

        foreach ($rows as $i => $row) {
            $errors = [];
            $needle = mb_strtolower($row['category'] ?? '');
            $category = $categories->first(fn (RoomCategory $c): bool => mb_strtolower($c->code) === $needle || collect($c->name)->contains(fn (string $n): bool => mb_strtolower($n) === $needle));
            $number = $row['number'] ?? '';

            if ($category === null) {
                $errors[] = (string) __('Unknown category ":category".', ['category' => $row['category'] ?? '']);
            }
            if ($number === '' || mb_strlen($number) > 10) {
                $errors[] = (string) __('Room number missing or too long.');
            } elseif (in_array($number, $existing, true) || in_array($number, $seen, true)) {
                $errors[] = (string) __('Room :number already exists.', ['number' => $number]);
            }
            $seen[] = $number;

            $result[] = ['line' => $i + 2, 'category' => $row['category'] ?? '', 'number' => $number, 'floor' => $row['floor'] ?? '', 'category_id' => $category?->id, 'errors' => $errors];
        }

        return $result;
    }

    /**
     * @param  list<array{line: int, category: string, number: string, floor: string, category_id: string|null, errors: list<string>}>  $preview
     */
    public function save(array $preview, User $by): int
    {
        $valid = array_values(array_filter($preview, fn (array $r): bool => $r['errors'] === [] && $r['category_id'] !== null));

        DB::transaction(function () use ($valid): void {
            foreach ($valid as $row) {
                Room::query()->create(['room_category_id' => $row['category_id'], 'number' => $row['number'], 'floor' => $row['floor'] !== '' ? $row['floor'] : null, 'status' => \App\Domains\Rooms\RoomStatus::InService]);
            }
        });

        Audit::record('rooms.imported', 'Rooms imported from a file', causer: $by, properties: ['rooms' => count($valid)]);

        return count($valid);
    }
}
