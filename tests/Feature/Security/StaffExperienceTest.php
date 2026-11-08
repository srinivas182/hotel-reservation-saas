<?php

declare(strict_types=1);

use App\Domains\Conversations\HandoverReason;
use App\Models\User;

it('sends platform staff without a hotel to the platform home', function (): void {
    $this->actingAs(User::factory()->platformAdmin()->withTwoFactor()->create())->get(route('dashboard'))->assertRedirect(route('platform.home'));
});

it('explains handovers to staff in plain words', function (): void {
    app()->setLocale('fr');

    expect(HandoverReason::label('ai_unavailable:no_credits'))->toBe("les crédits IA de l'hôtel sont épuisés")
        ->and(HandoverReason::label('ai_unavailable:something_new'))->toBe("l'assistant n'a pas pu répondre (service d'IA indisponible)")
        ->and(HandoverReason::label('order_guest_not_told:01ABC'))->toBe("le client n'a pas pu être informé de sa commande")
        ->and(HandoverReason::label('Le client demande à parler au gérant'))->toBe('Le client demande à parler au gérant')
        ->and(HandoverReason::label(null))->toBeNull();
});
