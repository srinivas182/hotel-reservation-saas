<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\BookingRoom;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Brick\Money\Money;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export of bookings overlapping a date range. Every export is audited.
 */
final class BookingExportController extends Controller
{
    public const MAX_DAYS = 366;

    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request, #[CurrentUser] User $user): StreamedResponse
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
        ]);

        $from = (string) $data['from'];
        $to = (string) $data['to'];
        abort_if(now()->parse($from)->diffInDays(now()->parse($to)) > self::MAX_DAYS, 422, __('Export at most one year at a time.'));

        $bookings = Booking::query()
            ->with(['guest', 'category', 'bookingRooms.room'])
            ->where('check_in', '<=', $to)
            ->where('check_out', '>', $from)
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->orderBy('check_in')
            ->orderBy('reference')
            ->get();

        Audit::record('bookings.exported', 'Bookings exported', subject: $hotel, causer: $user, properties: [
            'from' => $from,
            'to' => $to,
            'status' => $data['status'] ?? null,
            'rows' => $bookings->count(),
        ]);

        $filename = sprintf('bookings-%s-%s-to-%s.csv', $hotel->slug, $from, $to);

        return response()->streamDownload(function () use ($bookings, $locale): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            // UTF-8 byte order mark so spreadsheet programs read accents correctly.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['reference', 'status', 'channel', 'external_reference', 'guest', 'phone', 'email', 'room_category', 'check_in', 'check_out', 'nights', 'rooms', 'room_numbers', 'adults', 'children', 'total', 'currency', 'price_changed', 'created_at'], escape: '');

            foreach ($bookings as $booking) {
                fputcsv($out, [
                    $booking->reference,
                    $booking->status->value,
                    $booking->channel->value,
                    $booking->external_reference,
                    $this->safe((string) $booking->guest?->name()),
                    $booking->guest?->phone_e164,
                    $this->safe((string) $booking->guest?->email),
                    $this->safe((string) $booking->category?->localizedName($locale, $locale)),
                    $booking->check_in->toDateString(),
                    $booking->check_out->toDateString(),
                    $booking->nights(),
                    $booking->rooms,
                    implode(' ', $booking->bookingRooms->map(fn (BookingRoom $line): string => (string) $line->room?->number)->filter()->all()),
                    $booking->adults,
                    count($booking->children_ages),
                    (string) Money::ofMinor($booking->total_minor, $booking->currency)->getAmount(),
                    $booking->currency,
                    $booking->price_overridden ? 'yes' : 'no',
                    $booking->getAttribute('created_at')?->toIso8601String(),
                ], escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Prevent spreadsheet formula injection from free-text fields.
     */
    private function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
