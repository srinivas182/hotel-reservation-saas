<?php

declare(strict_types=1);

namespace App\Domains\Deposits\Jobs;

use App\Domains\Audit\Audit;
use App\Domains\Conversations\ConversationDirectory;
use App\Domains\Conversations\Exceptions\CannotSend;
use App\Domains\Conversations\MessageSender;
use App\Domains\Conversations\MessageSource;
use App\Domains\Deposits\Deposits;
use App\Domains\Deposits\Models\DepositLink;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Folio\Folio;
use App\Domains\Folio\RestaurantSettings;
use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells the guest about their deposit on WhatsApp (Sprint F):
 * - link: the payment link, sent (again) by staff;
 * - reminder: the rooms are released soon, with the link again;
 * - paid: the receipt, and what is left to pay at the hotel;
 * - late: the payment arrived after the rooms were released.
 * Free text within 24 hours of the guest's last message, otherwise the
 * approved template; when neither is possible, staff are asked to call.
 */
final class TellGuestAboutDeposit implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 60;

    public const TEMPLATES = ['link' => 'deposit_reminder', 'reminder' => 'deposit_reminder', 'paid' => 'deposit_received'];

    /**
     * @param  'link'|'reminder'|'paid'|'late'  $kind
     */
    public function __construct(public readonly string $linkId, public readonly string $kind) {}

    public function handle(MessageSender $sender, ConversationDirectory $directory, TenantContext $context, Deposits $deposits, Folio $folio): void
    {
        $link = DepositLink::query()->with('booking.guest')->find($this->linkId);
        $guest = $link?->booking->guest;

        if ($link === null || $guest === null || $guest->phone_e164 === null) {
            return;
        }

        $hotel = $context->hotel();
        $locale = $guest->preferred_locale ?? $hotel->default_locale;
        $money = fn (int $minor): string => MoneyFormatter::format(Money::ofMinor($minor, $link->currency), $locale);
        $booking = $link->booking;
        $deadline = $link->expires_at === null ? '' : self::localized($link->expires_at->setTimezone($hotel->timezone), $locale)->isoFormat('D MMMM HH:mm');
        $left = max(0, $folio->balance($booking));

        $values = [
            'name' => $guest->first_name,
            'hotel' => $hotel->name,
            'reference' => $booking->reference,
            'deposit' => $money($link->deposit_minor),
            'deadline' => $deadline,
            'url' => $deposits->url($link),
            'amount' => $money((int) $link->paid_minor),
            'method' => RestaurantSettings::methodLabel((string) $link->method),
            'receipt' => (string) $link->receipt_number,
            'left' => $money($left),
            'check_in' => self::localized($booking->check_in, $locale)->isoFormat('D MMMM'),
        ];

        $text = match ($this->kind) {
            'link' => __('Hello :name, here is the link to pay the deposit of :deposit for booking :reference at :hotel (rooms held until :deadline): :url', $values, $locale),
            'reminder' => __('Hello :name, a reminder from :hotel: your rooms for booking :reference are held until :deadline. Pay the deposit of :deposit here to confirm it: :url', $values, $locale),
            'paid' => __('Thank you :name! We received :amount by :method for booking :reference (receipt :receipt). Your booking is confirmed; see you on :check_in.', $values, $locale)
                .($left > 0 ? ' '.__('Left to pay at the hotel: :left.', $values, $locale) : ''),
            default => __('Hello :name, we received :amount for booking :reference, but the rooms had already been released. The hotel will contact you to rebook or refund you.', $values, $locale),
        };

        $conversation = $directory->forWaId(ltrim($guest->phone_e164, '+'));
        $how = null;

        try {
            if ($conversation->windowOpen()) {
                $sender->sendText($conversation, (string) $text, null, MessageSource::System);
                $how = 'text';
            } elseif (isset(self::TEMPLATES[$this->kind])) {
                $template = $this->template(self::TEMPLATES[$this->kind], $locale) ?? $this->template(self::TEMPLATES[$this->kind], $hotel->default_locale);
                if ($template !== null) {
                    $params = $this->kind !== 'paid'
                        ? [$values['name'], $values['hotel'], $values['reference'], $values['deadline'], $values['deposit'], $values['url']]
                        : [$values['name'], $values['amount'], $values['method'], $values['reference'], $values['receipt'], $values['check_in']];
                    $sender->sendTemplate($conversation, $template, array_map(fn (string $v): string => MessageTemplate::cleanValue($v), $params), null, MessageSource::System);
                    $how = 'template';
                }
            }
        } catch (CannotSend) {
            $how = null;
        }

        if ($how === null) {
            $conversation->forceFill(['needs_attention' => true, 'handover_reason' => 'deposit_not_told:'.$this->kind])->save();
        }

        Audit::record('deposits.guest_told', $how === null ? 'The guest could not be told about the deposit' : 'The guest was told about the deposit', subject: $booking, properties: ['kind' => $this->kind, 'how' => $how ?? 'none']);
    }

    private function template(string $name, string $locale): ?MessageTemplate
    {
        return MessageTemplate::query()->where('name', $name)->where('language', $locale)->where('status', TemplateStatus::Approved->value)->first();
    }

    private static function localized(CarbonImmutable $date, string $locale): CarbonImmutable
    {
        /** @var CarbonImmutable $localized */
        $localized = $date->locale($locale);

        return $localized;
    }
}
