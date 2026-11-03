<?php

declare(strict_types=1);

namespace App\Domains\Ai\Gateway;

use App\Domains\Ai\Models\AiProvider;
use Laravel\Ai\Ai;

/**
 * Registers platform providers (keys from the database) with laravel/ai at
 * run time, under a name per provider.
 */
final class ProviderRegistry
{
    public function register(AiProvider $provider): string
    {
        $name = $provider->configName();
        $credentials = $provider->credentials;

        config(["ai.providers.{$name}" => array_filter([
            'driver' => $provider->driver->value,
            'key' => $credentials['key'] ?? null,
            'url' => $credentials['url'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '')]);

        // A changed key must not reuse a provider built with the old one.
        Ai::forgetInstance($name);

        return $name;
    }
}
