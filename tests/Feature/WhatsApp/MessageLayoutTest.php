<?php

declare(strict_types=1);

use App\Domains\BookingRequests\DecisionMessages;

it('lays out the booking confirmation on separate lines, in French too', function (): void {
    $fr = DecisionMessages::default('confirmed', 'fr');

    expect($fr)->toContain("\n• Chambre : {room}\n")
        ->toContain('*{total}*')
        ->toContain('est confirmée')
        ->and(DecisionMessages::default('declined', 'en'))->toContain("\n\n");
});
