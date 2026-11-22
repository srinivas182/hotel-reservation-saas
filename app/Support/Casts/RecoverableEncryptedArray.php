<?php

declare(strict_types=1);

namespace App\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Encrypted array for provider credentials (API keys). Like Laravel's
 * "encrypted:array", but a value saved under another APP_KEY (key changed,
 * database copied from another server) reads as empty instead of breaking
 * the whole page: the screen then asks for the key again.
 *
 * Set APP_PREVIOUS_KEYS to read values saved under an earlier key.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
final class RecoverableEncryptedArray implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString((string) $value), true);
        } catch (DecryptException) {
            Log::warning('Saved secret cannot be read with the current APP_KEY; it must be entered again.', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'attribute' => $key,
            ]);

            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString((string) json_encode($value));
    }
}
