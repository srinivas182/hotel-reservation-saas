<?php

declare(strict_types=1);

use App\Domains\Assistant\Engine\AssistantEngine;
use App\Domains\Assistant\Engine\AssistantResult;
use App\Domains\Assistant\Engine\AssistantTurn;
use App\Domains\Assistant\Knowledge\KnowledgeEntry;
use App\Domains\Assistant\Knowledge\KnowledgePack;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/../Ai/helpers.php';
require_once __DIR__.'/../Rates/helpers.php';

/**
 * First Class Lodge with an Executive category, rates, French and English,
 * a published information pack and AI models.
 *
 * @return array{hotel: Hotel, exe: App\Domains\Rooms\Models\RoomCategory}
 */
function assistantHotel(): array
{
    $hotel = Hotel::factory()->create(['name' => 'First Class Lodge', 'currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'default_locale' => 'fr']);
    $exe = roomCategory($hotel, 'EXE', 3, ['name' => ['fr' => 'Exécutive', 'en' => 'Executive']]);
    rateFor($hotel, $exe);
    setUpAiModels();

    app(TenantContext::class)->run($hotel, function (): void {
        HotelSettings::current()->update(['enabled_locales' => ['fr', 'en']]);
        KnowledgeEntry::query()->create(['locale' => 'fr', 'section' => 'payments', 'content' => 'Paiement en espèces ou Orange Money. Acompte de 10 000 FCFA pour les réservations.']);
        KnowledgeEntry::query()->create(['locale' => 'en', 'section' => 'payments', 'content' => 'Cash or Orange Money.']);
        app(KnowledgePack::class)->publish();
    });

    return ['hotel' => $hotel, 'exe' => $exe];
}

/**
 * @param  list<AssistantTurn>|string  $turns
 */
function ask(Hotel $hotel, array|string $turns): AssistantResult
{
    $turns = is_string($turns) ? [AssistantTurn::guest($turns)] : $turns;

    return app(TenantContext::class)->run($hotel, fn (): AssistantResult => app(AssistantEngine::class)->respond($turns));
}

/**
 * @return array<string, mixed>
 */
function aiReply(string $reply, array $overrides = []): array
{
    return ['reply' => $reply, 'language' => 'fr', 'intent' => 'price_question', 'handover' => false, 'handover_reason' => '', 'confidence' => 0.9, ...$overrides];
}
