<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts;

use App\Models\User;

/**
 * An alert that staff can answer with buttons (Sprint B). Built per
 * person, because every button carries a code signed for that person.
 */
interface StaffCard
{
    /**
     * Null when this person cannot answer with buttons; they get the plain
     * alert instead.
     *
     * @return array{text: string, buttons: list<array{id: string, title: string}>, template: string, values: list<string>}|null
     */
    public function for(User $user, StaffAlertSettings $settings, string $locale): ?array;
}
