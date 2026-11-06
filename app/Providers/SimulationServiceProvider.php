<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Assistant\Engine\HotelAssistantAgent;
use App\Domains\Conversations\Translation\TranslationAgent;
use App\Domains\Simulation\SimulatedAi;
use App\Domains\Simulation\SimulatedMetaController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Transcription;
use RuntimeException;

/**
 * Staging-only simulation: points the WhatsApp client at the fake Meta API
 * and replaces AI with simulated answers. Refuses to run in production.
 */
final class SimulationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! config('simulation.enabled')) {
            return;
        }

        self::guard($this->app->isProduction());
        config(['whatsapp.graph_url' => rtrim((string) config('app.url'), '/').'/simulation/graph']);
        self::routes();
        self::fakeAi();
    }

    public static function guard(bool $production): void
    {
        if ($production) {
            throw new RuntimeException('SIMULATION_MODE must never be enabled in production.');
        }
    }

    public static function routes(): void
    {
        Route::middleware('api')->group(function (): void {
            Route::any('simulation/graph/{version}/{path}', [SimulatedMetaController::class, 'graph'])->where('path', '.*')->name('simulation.graph');
            Route::get('simulation/media/{id}', [SimulatedMetaController::class, 'media'])->name('simulation.media');
        });
    }

    public static function fakeAi(): void
    {
        HotelAssistantAgent::fake(fn (string $prompt): array => SimulatedAi::assistantReply($prompt));
        TranslationAgent::fake(fn (string $prompt): string => SimulatedAi::translation($prompt));
        Transcription::fake(function (): string {
            SimulatedAi::wait('ai');

            return 'Bonjour, avez-vous une chambre pour ce soir ?';
        });
    }
}
