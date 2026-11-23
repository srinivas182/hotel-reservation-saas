<?php

declare(strict_types=1);

namespace App\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Encrypted string for a single secret (an access token, a PIN). Like
 * Laravel's "encrypted" cast, but a value saved under another APP_KEY (key
 * changed, database copied from another server) reads as null instead of
 * throwing "The MAC is invalid" and stopping every job that touches the
 * model. The owner of the secret then sees that it must be entered again.
 *
 * Set APP_PREVIOUS_KEYS to read values saved under an earlier key.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class RecoverableEncrypted implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            Log::warning('Saved secret cannot be read with the current APP_KEY; it must be entered again.', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'attribute' => $key,
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString((string) $value);
    }

    /**
     * Whether the stored value exists but cannot be decrypted.
     */
    public static function unreadable(Model $model, string $key): bool
    {
        $raw = $model->getRawOriginal($key);

        if ($raw === null || $raw === '') {
            return false;
        }

        try {
            Crypt::decryptString((string) $raw);

            return false;
        } catch (DecryptException) {
            return true;
        }
    }
}
