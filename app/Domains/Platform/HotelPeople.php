<?php

declare(strict_types=1);

namespace App\Domains\Platform;

use App\Domains\Audit\Audit;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * 0.31.3: the platform admin adds a hotel's people directly. An existing
 * account is reused; otherwise the account is created with the name and
 * password the admin gives (shared with the person outside the platform;
 * they can change it in their settings). The password is never stored in
 * clear or written to the audit log.
 */
final class HotelPeople
{
    /**
     * @param  string  $prefix  form field prefix (owner_ on the hotel form, empty on the members form)
     */
    public function findOrCreate(string $email, ?string $firstName, ?string $lastName, ?string $password, string $locale, ?User $by, string $prefix = ''): User
    {
        $email = mb_strtolower(trim($email));
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $existing;
        }

        $data = Validator::make(
            [$prefix.'first_name' => $firstName, $prefix.'last_name' => $lastName, $prefix.'password' => $password],
            [
                $prefix.'first_name' => ['required', 'string', 'max:100'],
                $prefix.'last_name' => ['required', 'string', 'max:100'],
                $prefix.'password' => ['required', 'string', Password::defaults()],
            ],
            [
                $prefix.'first_name.required' => __('No account uses this e-mail yet: enter a first name to create it.'),
                $prefix.'last_name.required' => __('Enter a last name to create the account.'),
                $prefix.'password.required' => __('Enter a password to create the account.'),
            ],
        )->validate();

        $user = User::query()->forceCreate([
            'first_name' => trim((string) $data[$prefix.'first_name']),
            'last_name' => trim((string) $data[$prefix.'last_name']),
            'email' => $email,
            'password' => (string) $data[$prefix.'password'],
            'preferred_locale' => $locale,
            'email_verified_at' => now(),
        ]);

        Audit::record('platform.hotel_user_created', 'Hotel user account created by the platform', subject: $user, causer: $by, properties: ['email' => $email]);

        return $user;
    }

    /**
     * Whether an account already uses this e-mail (for the forms' hints).
     */
    public static function exists(string $email): bool
    {
        return User::query()->where('email', mb_strtolower(trim($email)))->exists();
    }
}
