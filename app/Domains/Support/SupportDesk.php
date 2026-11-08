<?php

declare(strict_types=1);

namespace App\Domains\Support;

use App\Domains\Audit\Audit;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Support\Mail\SupportMail;
use App\Domains\Support\Models\SupportMessage;
use App\Domains\Support\Models\SupportSettings;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Support requests between hotels and the platform team.
 */
final class SupportDesk
{
    /**
     * @param  array<string, mixed>  $context
     * @param  list<UploadedFile>  $files
     */
    public function open(Hotel $hotel, User $user, string $subject, string $category, string $body, array $context, array $files, bool $whatsapp): SupportTicket
    {
        $plan = Subscription::query()->withoutGlobalScopes()->with('plan')->where('hotel_id', $hotel->id)->first()?->plan?->code;
        $hours = SupportSettings::current()->hoursFor($plan);

        $ticket = DB::transaction(function () use ($hotel, $user, $subject, $category, $body, $context, $files, $whatsapp, $hours): SupportTicket {
            $ticket = SupportTicket::query()->create([
                'hotel_id' => $hotel->id, 'requester_id' => $user->id, 'subject' => $subject, 'category' => $category, 'status' => 'open',
                'priority' => $category === 'technical' ? 'high' : 'normal', 'context' => $context, 'notify_whatsapp' => $whatsapp && $user->phone_e164 !== null,
                'first_response_due_at' => now()->addHours($hours), 'last_activity_at' => now(),
            ]);
            $this->message($ticket, $user, 'hotel', $body, false, $files);

            return $ticket;
        });

        Audit::record('support.opened', 'Support request opened', subject: $hotel, causer: $user, properties: ['ticket' => $ticket->id, 'category' => $category]);
        $this->tellTeam($ticket, 'opened');

        return $ticket;
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function replyFromHotel(SupportTicket $ticket, User $user, string $body, array $files = []): SupportMessage
    {
        $message = $this->message($ticket, $user, 'hotel', $body, false, $files);
        $ticket->forceFill(['status' => 'open', 'solved_at' => null, 'last_activity_at' => now()])->save();
        $this->tellTeam($ticket, 'replied');

        return $message;
    }

    public function replyFromSupport(SupportTicket $ticket, User $agent, string $body, bool $internal, bool $aiDrafted = false, bool $solve = false): SupportMessage
    {
        $message = $this->message($ticket, $agent, 'support', $body, $internal, [], $aiDrafted);

        if (! $internal) {
            $ticket->forceFill([
                'first_responded_at' => $ticket->first_responded_at ?? now(),
                'assignee_id' => $ticket->assignee_id ?? $agent->id,
                'status' => $solve ? 'solved' : 'waiting_hotel',
                'solved_at' => $solve ? now() : null,
                'last_activity_at' => now(),
            ])->save();

            $requester = $ticket->requester;
            if ($requester?->email !== null) {
                Mail::to($requester->email)->queue(new SupportMail($ticket->id, $solve ? 'solved' : 'reply'));
            }
            if ($ticket->notify_whatsapp) {
                app(SupportWhatsApp::class)->notify($ticket);
            }
        }

        Audit::record($internal ? 'support.note' : 'support.replied', $internal ? 'Support note added' : 'Support replied', subject: $ticket->hotel, causer: $agent, properties: ['ticket' => $ticket->id, 'ai_drafted' => $aiDrafted]);

        return $message;
    }

    public function solve(SupportTicket $ticket, User $by): void
    {
        $ticket->forceFill(['status' => 'solved', 'solved_at' => now(), 'last_activity_at' => now()])->save();
        if ($ticket->requester?->email !== null) {
            Mail::to($ticket->requester->email)->queue(new SupportMail($ticket->id, 'solved'));
        }
        Audit::record('support.solved', 'Support request solved', subject: $ticket->hotel, causer: $by, properties: ['ticket' => $ticket->id]);
    }

    public function rate(SupportTicket $ticket, User $by, string $rating, ?string $comment): void
    {
        $ticket->forceFill(['rating' => $rating, 'rating_comment' => $comment])->save();
        Audit::record('support.rated', 'Support request rated', subject: $ticket->hotel, causer: $by, properties: ['ticket' => $ticket->id, 'rating' => $rating]);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function message(SupportTicket $ticket, User $author, string $side, string $body, bool $internal, array $files, bool $aiDrafted = false): SupportMessage
    {
        $paths = [];
        foreach (array_slice($files, 0, 3) as $file) {
            $name = Str::uuid().'.'.strtolower((string) $file->extension());
            Storage::disk('local')->putFileAs('support/'.$ticket->id, $file, $name);
            $paths[] = $name;
        }

        return SupportMessage::query()->create([
            'support_ticket_id' => $ticket->id, 'author_id' => $author->id, 'side' => $side, 'internal' => $internal,
            'body' => trim($body), 'attachments' => $paths === [] ? null : $paths, 'ai_drafted' => $aiDrafted,
        ]);
    }

    private function tellTeam(SupportTicket $ticket, string $kind): void
    {
        $to = $ticket->assignee?->email !== null
            ? [$ticket->assignee->email]
            : User::query()->whereIn('platform_role', [PlatformRole::Operations->value, PlatformRole::SuperAdmin->value])->whereNull('deactivated_at')->pluck('email')->all();

        foreach ($to as $email) {
            Mail::to((string) $email)->queue(new SupportMail($ticket->id, 'team_'.$kind));
        }
    }
}
