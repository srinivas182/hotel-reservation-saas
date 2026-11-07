<?php

declare(strict_types=1);

namespace App\Domains\Onboarding;

use App\Domains\Audit\Audit;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create room categories and their rooms in one go: "Standard × 12,
 * Supérieure × 4, Suite × 2" → rooms 101–112, 113–116, 117–118 (numbers
 * already used are skipped).
 */
final class QuickRooms
{
    /**
     * @param  list<array{name: string, count: int, adults: int}>  $categories
     * @return array{categories: int, rooms: int}
     */
    public function create(array $categories, int $firstNumber, User $by): array
    {
        $result = DB::transaction(function () use ($categories, $firstNumber): array {
            $used = Room::query()->pluck('number')->map(fn ($n): string => (string) $n)->all();
            $codes = array_values(array_map('strval', RoomCategory::query()->pluck('code')->all()));
            $number = $firstNumber;
            $rooms = 0;
            $created = 0;
            $sort = (int) RoomCategory::query()->max('sort_order');

            foreach ($categories as $row) {
                $name = trim($row['name']);
                $code = $this->code($name, $codes);
                $codes[] = $code;
                $category = RoomCategory::query()->firstOrCreate(
                    ['code' => $code],
                    [
                        'name' => ['fr' => $name, 'en' => $name],
                        'standard_occupancy' => max(1, min($row['adults'], 2)),
                        'max_adults' => max(1, $row['adults']),
                        'max_children' => 1,
                        'max_occupancy' => max(1, $row['adults']) + 1,
                        'extra_bed_allowed' => false,
                        'active' => true,
                        'sort_order' => ++$sort,
                    ],
                );
                $created++;

                for ($i = 0; $i < $row['count']; $i++) {
                    while (in_array((string) $number, $used, true)) {
                        $number++;
                    }
                    Room::query()->create(['room_category_id' => $category->id, 'number' => (string) $number, 'floor' => strlen((string) $number) >= 3 ? substr((string) $number, 0, -2) : null, 'status' => \App\Domains\Rooms\RoomStatus::InService]);
                    $used[] = (string) $number;
                    $number++;
                    $rooms++;
                }
            }

            return ['categories' => $created, 'rooms' => $rooms];
        });

        Audit::record('rooms.quick_created', 'Rooms created from the setup assistant', causer: $by, properties: $result);

        return $result;
    }

    /**
     * @param  list<string>  $taken
     */
    private function code(string $name, array $taken): string
    {
        $base = Str::upper(Str::substr(preg_replace('/[^A-Za-z]/', '', Str::ascii($name)) ?: 'CAT', 0, 3));
        $code = $base;
        $i = 2;

        while (in_array($code, $taken, true)) {
            $code = $base.$i++;
        }

        if ($code === '') {
            throw ValidationException::withMessages(['categories' => __('Give each category a name.')]);
        }

        return $code;
    }
}
