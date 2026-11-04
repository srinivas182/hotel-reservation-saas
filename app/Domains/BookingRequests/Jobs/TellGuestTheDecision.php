<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests\Jobs;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Audit\Audit;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestStatus;
use App\Domains\BookingRequests\DecisionMessages;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Conversations\Exceptions\CannotSend;
use App\Domains\Conversations\MessageSender;
use App\Domains\Conversations\MessageSource;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells the guest what happened to their WhatsApp request: free text
 * within 24 hours of their last message, otherwise the approved template.
 * When neither is possible, staff are asked to contact the guest.
 */
final class TellGuestTheDecision implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    public const TEMPLATES = [
        'confirmed' => 'booking_confirmed',
        'deposit' => 'booking_deposit_request',
        'declined' => 'booking_declined',
        'expired' => 'request_expired',
    ];

    public function __construct(public readonly string $requestId) {}

    public function handle(MessageSender $sender, TenantContext $context): void
    {
        $request = BookingRequest::query()->with(['guest', 'category', 'booking', 'conversation.contact'])->find($this->requestId);

        if ($request === null || $request->guest_notified !== null || $request->conversation === null) {
            return;
        }

        $kind = match ($request->status) {
            BookingRequestStatus::Approved => $request->booking?->status === BookingStatus::Tentative ? 'deposit' : 'confirmed',
            BookingRequestStatus::Declined => 'declined',
            BookingRequestStatus::Expired => 'expired',
            default => null,
        };

        if ($kind === null) {
            return;
        }

        $hotel = $context->hotel();
        $settings = HotelAssistantSettings::current();
        $locale = $request->guest->preferred_locale ?? $request->conversation->messages()->where('direction', 'in')->whereNotNull('language')->latest('sent_at')->value('language') ?? $hotel->default_locale;
        $values = $this->values($request, $kind, $locale, $settings);
        $conversation = $request->conversation;

        try {
            if ($conversation->windowOpen()) {
                $sender->sendText($conversation, DecisionMessages::fill(DecisionMessages::text($settings, $kind, $locale), $values), null, MessageSource::System);
                $this->done($request, $kind, 'text');

                return;
            }

            $template = $this->template($kind, $locale) ?? $this->template($kind, $hotel->default_locale);

            if ($template !== null) {
                $sender->sendTemplate($conversation, $template, $this->templateValues($kind, $values), null, MessageSource::System);
                $this->done($request, $kind, 'template');

                return;
            }
        } catch (CannotSend) {
            // Fall through: staff contact the guest.
        }

        $conversation->forceFill(['needs_attention' => true, 'handover_reason' => "guest_not_told:{$kind}"])->save();
        Audit::record('booking_requests.guest_not_told', 'The guest could not be told the decision on WhatsApp', subject: $request, properties: ['kind' => $kind]);
    }

    private function template(string $kind, string $locale): ?MessageTemplate
    {
        return MessageTemplate::query()
            ->where('name', self::TEMPLATES[$kind])
            ->where('language', $locale)
            ->where('status', TemplateStatus::Approved->value)
            ->first();
    }

    /**
     * @return array<string, string>
     */
    private function values(BookingRequest $request, string $kind, string $locale, HotelAssistantSettings $settings): array
    {
        $hotel = app(TenantContext::class)->hotel();
        $date = fn (CarbonImmutable $d): string => self::localized($d, $locale)->isoFormat('D MMMM YYYY');
        $booking = $request->booking;
        $total = Money::ofMinor($booking->total_minor ?? $request->total_minor, $request->currency);
        $deposit = $total->multipliedBy($settings->deposit_percent)->dividedBy(100, RoundingMode::Up);

        return [
            '{name}' => (string) ($request->guest->first_name ?? ''),
            '{reference}' => $booking->reference ?? $request->reference,
            '{room}' => (string) $request->category?->localizedName($locale, $hotel->default_locale),
            '{check_in}' => $date($booking->check_in ?? $request->check_in),
            '{check_out}' => $date($booking->check_out ?? $request->check_out),
            '{total}' => MoneyFormatter::format($total, $locale),
            '{deposit}' => MoneyFormatter::format($deposit, $locale),
            '{deposit_deadline}' => $booking?->tentative_until === null ? '' : self::localized($booking->tentative_until->setTimezone($hotel->timezone), $locale)->isoFormat('D MMMM HH:mm'),
            '{payment_instructions}' => (string) ($settings->deposit_instructions[$locale] ?? $settings->deposit_instructions[$hotel->default_locale] ?? ''),
            '{hotel}' => $hotel->name,
        ];
    }

    /**
     * Values in the order of the template's numbered placeholders.
     *
     * @param  array<string, string>  $v
     * @return list<string>
     */
    private function templateValues(string $kind, array $v): array
    {
        $values = match ($kind) {
            'confirmed' => [$v['{name}'], $v['{reference}'], $v['{room}'], $v['{check_in}'], $v['{check_out}'], $v['{total}'], $v['{hotel}']],
            'deposit' => [$v['{name}'], $v['{hotel}'], $v['{reference}'], $v['{room}'], $v['{check_in}'], $v['{check_out}'], $v['{deposit}'], $v['{deposit_deadline}'], $v['{payment_instructions}']],
            'declined' => [$v['{name}'], $v['{hotel}'], $v['{check_in}'], $v['{check_out}']],
            default => [$v['{name}'], $v['{check_in}'], $v['{check_out}']],
        };

        // Template values cannot be empty or contain line breaks.
        return array_map(fn (string $value): string => trim(preg_replace('/\s+/', ' ', $value) ?? '') ?: '-', $values);
    }

    private static function localized(CarbonImmutable $date, string $locale): CarbonImmutable
    {
        /** @var CarbonImmutable $localized */
        $localized = $date->locale($locale);

        return $localized;
    }

    private function done(BookingRequest $request, string $kind, string $how): void
    {
        $request->forceFill(['guest_notified' => $how])->save();
        Audit::record('booking_requests.guest_told', 'The guest was told the decision on WhatsApp', subject: $request, properties: ['kind' => $kind, 'how' => $how]);
    }
}
