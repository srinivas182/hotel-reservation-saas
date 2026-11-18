<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Availability\AvailabilityService;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\StaffActions\StaffActionRouter;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\StaffAlerts\StaffCard;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Brick\Money\Money;

/**
 * The WhatsApp card for a booking request (Sprint B): who, when, what,
 * how much, how many rooms stay free, whether the guest has stayed before,
 * with Accept and Decline buttons signed for each person.
 */
final class BookingRequestCard implements StaffCard
{
    public function __construct(
        private readonly BookingRequest $request,
        private readonly bool $escalated = false,
    ) {}

    public function for(User $user, StaffAlertSettings $settings, string $locale): ?array
    {
        if (! $settings->approvesOnWhatsApp($user)) {
            return null;
        }

        $request = $this->request->loadMissing(['guest', 'category']);
        $router = app(StaffActionRouter::class);
        $valid = max(60, (int) now()->diffInSeconds($request->expires_at, false));
        $lines = self::lines($request, $locale, $this->escalated);

        return [
            'text' => implode("\n", $lines),
            'buttons' => $router->buttons($request->id, $user, $valid, 'br_accept', (string) __('Accept', locale: $locale), 'br_decline', (string) __('Decline', locale: $locale)),
            'template' => 'booking_request_decision',
            'values' => [app(TenantContext::class)->hotel()->name, self::summary($request, $locale)],
        ];
    }

    /**
     * @return list<string>
     */
    public static function lines(BookingRequest $request, string $locale, bool $escalated = false): array
    {
        $hotel = app(TenantContext::class)->hotel();
        $settings = HotelAssistantSettings::current();
        $nights = $request->stay()->nights();
        $category = (string) $request->category?->localizedName($locale, $hotel->default_locale);
        $children = count($request->children_ages);
        $past = Booking::query()->where('guest_id', $request->guest_id)->where('status', BookingStatus::CheckedOut->value)->count();
        $left = self::roomsLeft($request);
        $minutes = (int) floor($request->created_at?->diffInMinutes(now()) ?? 0);

        return array_values(array_filter([
            $escalated ? __('⏰ No answer for :minutes minutes.', ['minutes' => $minutes], $locale) : null,
            __('🛎️ New booking request — :hotel', ['hotel' => $hotel->name], $locale),
            (string) $request->guest?->name().($past > 0 ? ' · '.__('returning guest (stay :n)', ['n' => $past + 1], $locale) : ''),
            implode(' · ', array_filter([
                $category,
                $request->check_in->settings(['locale' => $locale])->isoFormat('D MMM').' → '.$request->check_out->settings(['locale' => $locale])->isoFormat('D MMM'),
                trans_choice(':count night|:count nights', $nights, [], $locale),
                $request->rooms > 1 ? trans_choice(':count room|:count rooms', $request->rooms, [], $locale) : null,
                trans_choice(':count adult|:count adults', $request->adults, [], $locale).($children > 0 ? ', '.trans_choice(':count child|:count children', $children, [], $locale) : ''),
            ])),
            __('Total: :total', ['total' => MoneyFormatter::format(Money::ofMinor($request->total_minor, $request->currency), $locale)], $locale)
                .($settings->deposit_required ? ' · '.__('deposit :percent%', ['percent' => $settings->deposit_percent], $locale) : ''),
            $left === null ? null : ($left === 0
                ? __('After this one, no :category room is left on at least one night.', ['category' => $category], $locale)
                : trans_choice('After this one, :count :category room is left.|After this one, :count :category rooms are left.', $left, ['category' => $category], $locale)),
            __('Answer by :time · Ref. :reference', ['time' => $request->expires_at->setTimezone($hotel->timezone)->format('H:i'), 'reference' => $request->reference], $locale),
        ], fn ($line): bool => $line !== null && $line !== ''));
    }

    /**
     * One line, for the template (Meta does not allow line breaks in values).
     */
    public static function summary(BookingRequest $request, string $locale): string
    {
        $hotel = app(TenantContext::class)->hotel();

        return sprintf(
            '%s, %s, %s → %s (%s), %s, %s',
            (string) $request->guest?->name(),
            (string) $request->category?->localizedName($locale, $hotel->default_locale),
            $request->check_in->format('d/m'),
            $request->check_out->format('d/m'),
            trans_choice(':count night|:count nights', $request->stay()->nights(), [], $locale),
            trans_choice(':count adult|:count adults', $request->adults, [], $locale),
            MoneyFormatter::format(Money::ofMinor($request->total_minor, $request->currency), $locale),
        );
    }

    /**
     * The fewest rooms of this type still free on any night of the stay,
     * with this request's rooms already counted as taken (they are held).
     */
    private static function roomsLeft(BookingRequest $request): ?int
    {
        // The last argument is the last night, not the departure day.
        $nights = app(AvailabilityService::class)->availableFor($request->room_category_id, $request->check_in, $request->check_out->subDay());

        return $nights === [] ? null : max(0, min($nights));
    }
}
