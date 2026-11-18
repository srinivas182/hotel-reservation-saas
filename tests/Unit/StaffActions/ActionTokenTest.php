<?php

declare(strict_types=1);

use App\Domains\StaffActions\ActionToken;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

it('signs a button code for one hotel, person, subject and action', function (): void {
    $hotel = '01JHOTEL0000000000000000000';
    $code = ActionToken::make($hotel, 'br_accept', '01JREQUEST00000000000000000', '01JUSER000000000000000000A', CarbonImmutable::now()->addHour());
    $token = ActionToken::parse($hotel, $code);

    expect(strlen($code))->toBeLessThanOrEqual(128)
        ->and(ActionToken::looksLikeOne($code))->toBeTrue()
        ->and($token?->action)->toBe('br_accept')
        ->and($token?->prefix())->toBe('br')
        ->and($token?->subject)->toBe('01JREQUEST00000000000000000')
        ->and($token?->userId)->toBe('01JUSER000000000000000000A')
        ->and($token?->expired())->toBeFalse()
        // Another hotel, another person or action, a cut code: refused.
        ->and(ActionToken::parse('01JOTHERHOTEL00000000000000', $code))->toBeNull()
        ->and(ActionToken::parse($hotel, str_replace('br_accept', 'br_full', $code)))->toBeNull()
        ->and(ActionToken::parse($hotel, str_replace('000A', '000B', $code)))->toBeNull()
        ->and(ActionToken::parse($hotel, substr($code, 0, -1)))->toBeNull()
        ->and(ActionToken::parse($hotel, 'hello'))->toBeNull();

    $old = ActionToken::parse($hotel, ActionToken::make($hotel, 'undo', 'x', 'y', CarbonImmutable::now()->subSecond()));
    expect($old?->expired())->toBeTrue();
});
