<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Imports;

use App\Domains\Audit\Audit;
use App\Domains\Bookings\BookingChannel;
use App\Domains\Bookings\BookingService;
use App\Domains\Bookings\Exceptions\BookingNotPossible;
use App\Domains\Bookings\NewBooking;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Upcoming bookings from the hotel's old system or notebook. Each row is
 * booked through the normal booking rules (availability, guests, holds);
 * rows that do not fit are reported, never forced.
 */
final class BookingImport
{
    public const COLUMNS = ['first_name', 'last_name', 'phone', 'email', 'check_in', 'check_out', 'category', 'rooms', 'adults', 'total', 'reference'];

    public const REQUIRED = ['first_name', 'check_in', 'check_out', 'category', 'adults'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array<string, mixed>>
     */
    public function preview(array $rows): array
    {
        $categories = RoomCategory::query()->get();
        $today = HotelClock::today($this->context->hotel());
        $result = [];

        foreach ($rows as $i => $row) {
            $errors = [];
            $checkIn = $this->date($row['check_in'] ?? '');
            $checkOut = $this->date($row['check_out'] ?? '');
            $needle = mb_strtolower($row['category'] ?? '');
            $category = $categories->first(fn (RoomCategory $c): bool => mb_strtolower($c->code) === $needle || collect($c->name)->contains(fn (string $n): bool => mb_strtolower($n) === $needle));

            if (trim($row['first_name'] ?? '') === '') {
                $errors[] = (string) __('Guest first name missing.');
            }
            if ($checkIn === null || $checkOut === null || $checkOut->lte($checkIn)) {
                $errors[] = (string) __('Dates missing or in the wrong order (use YYYY-MM-DD or DD/MM/YYYY).');
            } elseif ($checkOut->lte($today)) {
                $errors[] = (string) __('This stay is already over: only upcoming or current stays are imported.');
            }
            if ($category === null) {
                $errors[] = (string) __('Unknown category ":category".', ['category' => $row['category'] ?? '']);
            }
            if ((int) ($row['adults'] ?? 0) < 1) {
                $errors[] = (string) __('Number of adults missing.');
            }
            if (($row['total'] ?? '') !== '' && ! is_numeric(str_replace([' ', ','], ['', '.'], (string) $row['total']))) {
                $errors[] = (string) __('Total is not a number.');
            }

            $result[] = [
                'line' => $i + 2,
                'guest' => trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')),
                'phone' => $row['phone'] ?? '',
                'email' => $row['email'] ?? '',
                'first_name' => $row['first_name'] ?? '',
                'last_name' => $row['last_name'] ?? '',
                'check_in' => $checkIn?->toDateString(),
                'check_out' => $checkOut?->toDateString(),
                'category' => $row['category'] ?? '',
                'category_id' => $category?->id,
                'rooms' => max(1, (int) ($row['rooms'] ?? 1)),
                'adults' => (int) ($row['adults'] ?? 0),
                'total' => ($row['total'] ?? '') === '' ? null : str_replace([' ', ','], ['', '.'], (string) $row['total']),
                'reference' => $row['reference'] ?? '',
                'errors' => $errors,
            ];
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $preview
     * @return array{saved: int, refused: list<array{line: int, reason: string}>}
     */
    public function save(array $preview, User $by): array
    {
        $saved = 0;
        $refused = [];
        $currency = $this->context->hotel()->currency;

        foreach ($preview as $row) {
            if ($row['errors'] !== [] || $row['category_id'] === null) {
                continue;
            }

            try {
                app(BookingService::class)->create(new NewBooking(
                    stay: new StayRequest((string) $row['category_id'], CarbonImmutable::parse((string) $row['check_in']), CarbonImmutable::parse((string) $row['check_out']), adults: (int) $row['adults'], rooms: (int) $row['rooms']),
                    guest: ['first_name' => (string) $row['first_name'], 'last_name' => (string) $row['last_name'] ?: null, 'phone' => (string) $row['phone'] ?: null, 'email' => (string) $row['email'] ?: null],
                    channel: BookingChannel::Other,
                    externalReference: (string) $row['reference'] ?: null,
                    notes: (string) __('Imported from a file'),
                    overrideTotal: $row['total'] === null ? null : Money::of((string) $row['total'], $currency, roundingMode: RoundingMode::HalfUp),
                    overrideReason: $row['total'] === null ? null : (string) __('Price from the previous system'),
                ), $by);
                $saved++;
            } catch (BookingNotPossible $e) {
                $refused[] = ['line' => (int) $row['line'], 'reason' => $e->getMessage()];
            } catch (Throwable $e) {
                report($e);
                $refused[] = ['line' => (int) $row['line'], 'reason' => (string) __('This booking could not be saved.')];
            }
        }

        Audit::record('bookings.imported', 'Bookings imported from a file', causer: $by, properties: ['saved' => $saved, 'refused' => count($refused)]);

        return ['saved' => $saved, 'refused' => $refused];
    }

    private function date(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);
            } catch (Throwable) {
                $date = null;
            }

            if ($date instanceof CarbonImmutable && $date->format(substr($format, 1)) === $value) {
                return $date;
            }
        }

        return null;
    }
}
