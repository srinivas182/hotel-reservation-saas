<?php

declare(strict_types=1);

namespace App\Domains\Bookings;

use App\Domains\Bookings\Models\Guest;

/**
 * Finds guests by phone (the key WhatsApp will use from S6) and creates
 * or updates them from booking forms.
 */
final class GuestDirectory
{
    public static function normalisePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $clean = preg_replace('/[\s\-().]/', '', $phone) ?? '';

        if (str_starts_with($clean, '00')) {
            $clean = '+'.substr($clean, 2);
        }

        return $clean === '' ? null : $clean;
    }

    public function findByPhone(?string $phone): ?Guest
    {
        $phone = self::normalisePhone($phone);

        return $phone === null ? null : Guest::query()->where('phone_e164', $phone)->first();
    }

    /**
     * @param  array{first_name: string, last_name?: string|null, phone?: string|null, email?: string|null, preferred_locale?: string|null}  $data
     */
    public function findOrCreate(array $data): Guest
    {
        $phone = self::normalisePhone($data['phone'] ?? null);
        $guest = $this->findByPhone($phone) ?? new Guest(['phone_e164' => $phone]);

        $guest->fill(array_filter([
            'first_name' => trim($data['first_name']),
            'last_name' => isset($data['last_name']) ? trim((string) $data['last_name']) : null,
            'email' => $data['email'] ?? null,
            'preferred_locale' => $data['preferred_locale'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== ''));

        $guest->save();

        return $guest;
    }
}
