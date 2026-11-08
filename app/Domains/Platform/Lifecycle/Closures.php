<?php

declare(strict_types=1);

namespace App\Domains\Platform\Lifecycle;

use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Audit\Audit;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Platform\Lifecycle\Mail\ClosureMail;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Closing a hotel, reopening it, and deleting its data 90 days later.
 */
final class Closures
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly HotelExport $export,
    ) {}

    public function close(Hotel $hotel, User $by, string $byRole, ?string $reason): HotelClosure
    {
        if (HotelClosure::openFor($hotel->id) !== null) {
            throw new RuntimeException((string) __('This hotel is already closed.'));
        }

        $closure = DB::transaction(function () use ($hotel, $by, $byRole, $reason): HotelClosure {
            $closure = HotelClosure::query()->create([
                'hotel_id' => $hotel->id, 'requested_by' => $by->id, 'by_role' => $byRole, 'reason' => $reason,
                'previous_status' => $hotel->status->value, 'closed_at' => now(), 'delete_after' => now()->addDays(HotelClosure::KEEP_DAYS),
            ]);

            $this->context->run($hotel, function (): void {
                // Nothing new is invoiced; the paid period simply runs out.
                Subscription::query()->update(['status' => 'cancelled', 'pending_plan_id' => null]);
                HotelAssistantSettings::current()->forceFill(['mode' => AssistantMode::Off])->save();
                // The number stays the hotel's; the platform stops using it.
                WhatsAppAccount::query()->update(['status' => AccountStatus::Disconnected->value, 'token' => null]);
            });

            $hotel->forceFill(['status' => HotelStatus::Closed])->save();

            return $closure;
        });

        $this->context->run($hotel, fn () => Audit::record('hotel.closed', 'Hotel closed', subject: $hotel, causer: $by, properties: ['by' => $byRole, 'reason' => $reason, 'delete_after' => $closure->delete_after->toDateString()]));
        $this->prepareExport($closure);
        $this->notify($closure, 'closed');

        return $closure;
    }

    public function prepareExport(HotelClosure $closure): void
    {
        $hotel = Hotel::query()->findOrFail($closure->hotel_id);
        $closure->forceFill(['export_path' => $this->export->build($hotel), 'exported_at' => now()])->save();
    }

    public function reopen(Hotel $hotel, User $by): void
    {
        $closure = HotelClosure::openFor($hotel->id) ?? throw new RuntimeException((string) __('This hotel is not closed.'));

        DB::transaction(function () use ($closure, $hotel): void {
            $closure->forceFill(['reopened_at' => now()])->save();
            $hotel->forceFill(['status' => HotelStatus::from($closure->previous_status === HotelStatus::Closed->value ? HotelStatus::Active->value : $closure->previous_status)])->save();
        });

        $this->context->run($hotel, fn () => Audit::record('hotel.reopened', 'Hotel reopened', subject: $hotel, causer: $by));
    }

    /**
     * Reminders 30 and 7 days before deletion, then deletion.
     *
     * @return array{reminders: int, purged: int}
     */
    public function sweep(): array
    {
        $reminders = 0;
        $purged = 0;

        foreach (HotelClosure::query()->whereNull('reopened_at')->whereNull('purged_at')->get() as $closure) {
            $daysLeft = (int) now()->startOfDay()->diffInDays($closure->delete_after->startOfDay(), false);

            if ($daysLeft <= 0) {
                $this->purge($closure);
                $purged++;

                continue;
            }

            $sent = $closure->reminders ?? [];
            foreach ([30, 7] as $day) {
                if ($daysLeft <= $day && ! isset($sent["d{$day}"])) {
                    $sent["d{$day}"] = now()->toIso8601String();
                    $closure->forceFill(['reminders' => $sent])->save();
                    $this->notify($closure, "reminder_{$day}");
                    $reminders++;

                    break;
                }
            }
        }

        return ['reminders' => $reminders, 'purged' => $purged];
    }

    /**
     * Delete everything the hotel held, except invoices and the closure record.
     */
    public function purge(HotelClosure $closure): void
    {
        $hotel = Hotel::query()->findOrFail($closure->hotel_id);

        foreach (HotelTables::deletionOrder() as [$table, $via]) {
            $via === null
                ? DB::table($table)->where('hotel_id', $hotel->id)->delete()
                : DB::table($table)->whereIn($via['column'], DB::table($via['parent'])->where('hotel_id', $hotel->id)->select($via['parent_column']))->delete();
        }

        foreach (HotelTables::folders($hotel->id) as $folder) {
            Storage::disk('local')->deleteDirectory($folder);
        }
        Storage::disk('local')->deleteDirectory('private/exports/'.$hotel->id);
        if (is_dir(storage_path('app/private/exports/'.$hotel->id))) {
            array_map('unlink', glob(storage_path('app/private/exports/'.$hotel->id.'/*')) ?: []);
            @rmdir(storage_path('app/private/exports/'.$hotel->id));
        }

        $hotel->members()->detach();
        $hotel->forceFill(['name' => 'Deleted hotel '.substr($hotel->id, -6), 'legal_name' => null, 'owner_user_id' => null])->save();
        $closure->forceFill(['purged_at' => now(), 'export_path' => null])->save();

        // Recorded on the platform side: the hotel's own history is gone.
        Audit::record('hotel.purged', 'Hotel data deleted after closure', properties: ['hotel_id' => $hotel->id, 'closed_at' => $closure->closed_at->toDateString()]);
    }

    private function notify(HotelClosure $closure, string $kind): void
    {
        $owner = Hotel::query()->with('owner')->find($closure->hotel_id)?->owner;

        if ($owner?->email !== null) {
            Mail::to($owner->email)->queue(new ClosureMail($closure->id, $kind));
        }
    }
}
