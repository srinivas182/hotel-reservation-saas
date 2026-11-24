<?php

declare(strict_types=1);

use App\Domains\Conversations\CardNumberMask;

it('masks a valid card number and keeps the last four digits', function (string $typed): void {
    expect(CardNumberMask::mask("Ma carte : {$typed} merci"))->toBe('Ma carte : •••• •••• •••• 4242 merci');
})->with([
    'grouped' => ['4242 4242 4242 4242'],
    'joined' => ['4242424242424242'],
    'dashes' => ['4242-4242-4242-4242'],
]);

it('keeps numbers that are not card numbers', function (string $text): void {
    expect(CardNumberMask::mask($text))->toBe($text);
})->with([
    'phone' => ['Appelez le +225 07 07 07 07 07'],
    'reference' => ['Réservation CTH-26-0008 pour 2 adultes'],
    'not luhn' => ['1234 5678 9012 3456'],
]);

it('masks the security code and the expiry date', function (): void {
    expect(CardNumberMask::mask('CVV 123, exp 08/29'))->toBe('CVV •••, exp ••/••')
        ->and(CardNumberMask::mask('cryptogramme: 4567'))->toBe('cryptogramme: •••');
});

it('leaves empty text alone', function (): void {
    expect(CardNumberMask::mask(null))->toBeNull()->and(CardNumberMask::mask(''))->toBe('');
});
