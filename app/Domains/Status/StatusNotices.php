<?php

declare(strict_types=1);

namespace App\Domains\Status;

use App\Domains\Audit\Audit;
use App\Domains\Status\Mail\StatusMail;
use App\Domains\Status\Models\StatusNotice;
use App\Domains\Status\Models\StatusSubscriber;
use App\Domains\Status\Models\StatusUpdate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Posting incidents and maintenance, and alerting subscribers.
 */
final class StatusNotices
{
    /**
     * @param  array{fr: string, en: string}  $title
     * @param  array{fr: string, en: string}  $body
     * @param  list<string>  $components
     * @param  list<string>|null  $countries
     */
    public function post(array $title, array $body, string $level, array $components, ?array $countries, CarbonImmutable $startsAt, ?CarbonImmutable $endsAt, User $by): StatusNotice
    {
        $notice = StatusNotice::query()->create([
            'title' => $title, 'level' => $level, 'components' => $components, 'countries' => $countries === [] ? null : $countries,
            'starts_at' => $startsAt, 'ends_at' => $endsAt, 'created_by' => $by->id,
        ]);
        $this->update($notice, $body, $level === 'maintenance' ? 'scheduled' : 'investigating', $by);
        Audit::record('status.notice_posted', 'Status notice posted', causer: $by, properties: ['notice' => $notice->id, 'level' => $level]);

        return $notice;
    }

    /**
     * @param  array{fr: string, en: string}  $body
     */
    public function update(StatusNotice $notice, array $body, string $state, User $by): StatusUpdate
    {
        $update = StatusUpdate::query()->create(['status_notice_id' => $notice->id, 'body' => $body, 'state' => $state, 'created_by' => $by->id]);

        if ($state === 'resolved' && $notice->resolved_at === null) {
            $notice->forceFill(['resolved_at' => now()])->save();
        }

        StatusSubscriber::query()->whereNotNull('confirmed_at')->get()
            ->filter(fn (StatusSubscriber $s): bool => $notice->concerns($s->country_code) || $s->country_code === null)
            ->each(fn (StatusSubscriber $s) => Mail::to($s->email)->queue(new StatusMail($s->id, $notice->id, (string) ($body[$s->locale] ?? $body['fr']))));

        return $update;
    }
}
