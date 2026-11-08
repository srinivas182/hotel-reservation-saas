<?php

declare(strict_types=1);

namespace App\Domains\Platform\Announcements;

use App\Domains\Billing\Models\Subscription;
use App\Domains\Platform\Announcements\Mail\AnnouncementMail;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class Announcements
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Active announcements for the current hotel and user (not dismissed).
     *
     * @return array<int, array{id: int, title: string, body: string, level: string, dismissible: bool}>
     */
    public function forCurrent(User $user, string $locale): array
    {
        if (! $this->context->has()) {
            return [];
        }

        $hotel = $this->context->hotel();
        $plan = Subscription::query()->with('plan')->first()?->plan?->code;
        $dismissed = DB::table('announcement_dismissals')->where('user_id', $user->id)->pluck('announcement_id')->all();

        return Announcement::query()->where('starts_at', '<=', now())->where('ends_at', '>', now())->orderBy('starts_at')->get()
            ->filter(fn (Announcement $a): bool => $a->targets($hotel->id, $hotel->country_code, $plan) && ($a->level === 'maintenance' || ! in_array($a->id, $dismissed, true)))
            ->map(fn (Announcement $a): array => ['id' => $a->id, 'title' => $a->text('title', $locale), 'body' => $a->text('body', $locale), 'level' => $a->level, 'dismissible' => $a->level !== 'maintenance'])
            ->values()->all();
    }

    /**
     * Email announcements that have started and asked for it, once.
     */
    public function sendDue(): int
    {
        $sent = 0;

        foreach (Announcement::query()->where('email', true)->whereNull('emailed_at')->where('starts_at', '<=', now())->where('ends_at', '>', now())->get() as $announcement) {
            foreach (Hotel::query()->with('owner')->get() as $hotel) {
                $plan = $this->context->run($hotel, fn () => Subscription::query()->with('plan')->first()?->plan?->code);

                if ($hotel->owner?->email !== null && $hotel->status->allowsAccess() && $announcement->targets($hotel->id, $hotel->country_code, $plan)) {
                    Mail::to($hotel->owner->email)->locale($hotel->default_locale)->queue(new AnnouncementMail($announcement->id));
                    $sent++;
                }
            }

            $announcement->forceFill(['emailed_at' => now()])->save();
        }

        return $sent;
    }
}
