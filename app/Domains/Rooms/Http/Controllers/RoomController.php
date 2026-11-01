<?php

declare(strict_types=1);

namespace App\Domains\Rooms\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\RoomStatus;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class RoomController extends Controller
{
    private const BULK_LIMIT = 200;

    public function __construct(private readonly TenantContext $context) {}

    public function store(Request $request): RedirectResponse
    {
        $room = Room::query()->create($this->validated($request, null));

        Audit::record('rooms.room_created', 'Room created', subject: $this->context->hotel(), properties: $room->only(['id', 'number', 'room_category_id']));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room :number added.', ['number' => $room->number])]);

        return back();
    }

    public function update(Request $request, Room $room): RedirectResponse
    {
        $before = $room->only(['number', 'room_category_id', 'floor', 'status']);
        $room->update($this->validated($request, $room));

        Audit::record('rooms.room_updated', 'Room updated', subject: $this->context->hotel(), properties: [
            'room_id' => $room->id,
            'old' => $before,
            'new' => $room->only(['number', 'room_category_id', 'floor', 'status']),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room :number updated.', ['number' => $room->number])]);

        return back();
    }

    public function destroy(Room $room): RedirectResponse
    {
        $data = $room->only(['id', 'number', 'room_category_id']);
        $room->delete();

        Audit::record('rooms.room_deleted', 'Room deleted', subject: $this->context->hotel(), properties: $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room :number removed.', ['number' => $data['number']])]);

        return back();
    }

    /**
     * Create rooms with consecutive numbers, for example 101 to 110.
     * Numbers that already exist are skipped.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $hotelId = $this->context->id();

        $data = $request->validate([
            'room_category_id' => ['required', 'string', Rule::exists('room_categories', 'id')->where('hotel_id', $hotelId)],
            'prefix' => ['nullable', 'string', 'max:5', 'regex:/^[A-Za-z0-9\-]*$/'],
            'from' => ['required', 'integer', 'min:0', 'max:99999'],
            'to' => ['required', 'integer', 'gte:from', 'max:99999', function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                if ((int) $value - (int) $request->input('from') + 1 > self::BULK_LIMIT) {
                    $fail(__('Add at most :count rooms at a time.', ['count' => self::BULK_LIMIT]));
                }
            }],
            'floor' => ['nullable', 'string', 'max:20'],
        ]);

        $prefix = (string) ($data['prefix'] ?? '');
        $numbers = array_map(fn (int $n): string => $prefix.$n, range((int) $data['from'], (int) $data['to']));
        $existing = Room::query()->whereIn('number', $numbers)->pluck('number')->all();
        $new = array_values(array_diff($numbers, $existing));

        DB::transaction(function () use ($new, $data): void {
            foreach ($new as $number) {
                Room::query()->create([
                    'room_category_id' => $data['room_category_id'],
                    'number' => $number,
                    'floor' => $data['floor'] ?? null,
                    'status' => RoomStatus::InService,
                ]);
            }
        });

        Audit::record('rooms.bulk_created', 'Rooms added in bulk', subject: $this->context->hotel(), properties: [
            'room_category_id' => $data['room_category_id'],
            'created' => $new,
            'skipped' => $existing,
        ]);

        Inertia::flash('toast', [
            'type' => $existing === [] ? 'success' : 'warning',
            'message' => $existing === []
                ? __(':count rooms added.', ['count' => count($new)])
                : __(':count rooms added. Already existed: :numbers.', ['count' => count($new), 'numbers' => implode(', ', $existing)]),
        ]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Room $room): array
    {
        $hotelId = $this->context->id();
        $request->merge(['number' => trim((string) $request->input('number'))]);

        return $request->validate([
            'room_category_id' => ['required', 'string', Rule::exists('room_categories', 'id')->where('hotel_id', $hotelId)],
            'number' => [
                'required', 'string', 'max:20',
                Rule::unique('rooms', 'number')->where('hotel_id', $hotelId)->ignore($room?->getKey()),
            ],
            'floor' => ['nullable', 'string', 'max:20'],
            'status' => ['required', Rule::enum(RoomStatus::class)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
