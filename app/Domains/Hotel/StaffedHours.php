<?php

declare(strict_types=1);

namespace App\Domains\Hotel;

use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\Models\Hotel;
use Carbon\CarbonImmutable;

/**
 * When reception is staffed (Hotel settings, per weekday, in the hotel's
 * time zone). With no hours set, reception counts as always staffed.
 */
final class StaffedHours
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param  array<string, list<array{from: string, to: string}>>|null  $hours
     */
    public function __construct(private readonly Hotel $hotel, private readonly ?array $hours) {}

    public static function forCurrentHotel(Hotel $hotel): self
    {
        return new self($hotel, HotelSettings::current()->staffed_hours);
    }

    public function isOpen(?CarbonImmutable $at = null): bool
    {
        if ($this->hours === null || $this->hours === [] || $this->allEmpty()) {
            return true;
        }

        $local = ($at ?? CarbonImmutable::now())->setTimezone($this->hotel->timezone);
        $time = $local->format('H:i');

        foreach ($this->hours[self::DAYS[$local->dayOfWeekIso - 1]] ?? [] as $interval) {
            if ($time >= $interval['from'] && $time < $interval['to']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The next moment reception opens (now if it is open), in the hotel's
     * time zone; null when no hours are set.
     */
    public function nextOpening(?CarbonImmutable $at = null): ?CarbonImmutable
    {
        $local = ($at ?? CarbonImmutable::now())->setTimezone($this->hotel->timezone);

        if ($this->isOpen($local)) {
            return $local;
        }

        if ($this->hours === null || $this->allEmpty()) {
            return null;
        }

        for ($day = 0; $day <= 7; $day++) {
            $date = $local->addDays($day);
            $starts = array_map(fn (array $i): string => $i['from'], $this->hours[self::DAYS[$date->dayOfWeekIso - 1]] ?? []);
            sort($starts);

            foreach ($starts as $start) {
                [$h, $m] = array_map('intval', explode(':', $start));
                $candidate = $date->setTime($h, $m);

                if ($candidate->gt($local)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function allEmpty(): bool
    {
        foreach ((array) $this->hours as $intervals) {
            if ($intervals !== []) {
                return false;
            }
        }

        return true;
    }
}
