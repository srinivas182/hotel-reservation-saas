<?php

declare(strict_types=1);

namespace App\Domains\Rooms\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomClosure;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class RoomClosureController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function store(Request $request, Room $room): RedirectResponse
    {
        $data = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $overlaps = $room->closures()
            ->where('from_date', '<=', $data['to_date'])
            ->where('to_date', '>=', $data['from_date'])
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['from_date' => __('This room is already closed for part of these dates.')]);
        }

        $closure = RoomClosure::query()->create([...$data, 'room_id' => $room->id]);

        Audit::record('rooms.closure_added', 'Room closed for dates', subject: $this->context->hotel(), properties: [
            'room' => $room->number,
            'from' => $data['from_date'],
            'to' => $data['to_date'],
            'reason' => $data['reason'] ?? null,
            'closure_id' => $closure->id,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room :number closed for those dates.', ['number' => $room->number])]);

        return back();
    }

    public function destroy(RoomClosure $roomClosure): RedirectResponse
    {
        $room = $roomClosure->room;
        $data = [
            'room' => $room?->number,
            'from' => $roomClosure->from_date->toDateString(),
            'to' => $roomClosure->to_date->toDateString(),
        ];
        $roomClosure->delete();

        Audit::record('rooms.closure_removed', 'Room closure removed', subject: $this->context->hotel(), properties: $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Closure removed.')]);

        return back();
    }
}
