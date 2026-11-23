<?php

declare(strict_types=1);

use App\Domains\Assistant\Engine\Amounts;
use App\Domains\Assistant\Engine\ToolContext;
use App\Domains\Assistant\Engine\Tools\CheckAvailability;
use App\Domains\Assistant\Engine\Tools\GetQuote;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Laravel\Ai\Tools\Request;

require_once __DIR__.'/helpers.php';

it('finds and normalises amounts in many formats', function (string $text, array $expected): void {
    expect(array_column(Amounts::find($text), 'normalised'))->toBe($expected);
})->with([
    ['Total : 50 000 FCFA', ['50000']],
    ['Total: 50,000 XOF', ['50000']],
    ["Prix 25\u{202F}000\u{00A0}F CFA par nuit", ['25000']],
    ['€120,50 per night', ['120.5']],
    ['XOF 75.000 au total', ['75000']],
    ['Du 12 au 14 novembre 2026, 2 personnes', []],
    ['Chambre 101, étage 2', []],
]);

it('returns exact quotes and availability for the current hotel only', function (): void {
    $this->travelTo('2026-11-01 10:00:00');
    ['hotel' => $hotel] = assistantHotel();
    $other = Hotel::factory()->create();
    $otherCategory = roomCategory($other, 'SUI', 1);
    rateFor($other, $otherCategory);

    app(TenantContext::class)->run($hotel, function (): void {
        $context = new ToolContext('fr');

        $quote = json_decode((string) (new GetQuote($context))->handle(new Request(['category_code' => 'EXE', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14', 'adults' => 2])), true);
        expect($quote['bookable'])->toBeTrue()
            ->and($context->allowsAmount('50000'))->toBeTrue()
            ->and($context->allowsAmount('45000'))->toBeFalse();

        $foreign = json_decode((string) (new GetQuote($context))->handle(new Request(['category_code' => 'SUI', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14', 'adults' => 2])), true);
        expect($foreign['error'])->toContain('Unknown room category');

        $availability = json_decode((string) (new CheckAvailability($context))->handle(new Request(['check_in' => '2026-11-12', 'check_out' => '2026-11-14'])), true);
        expect($availability['categories'])->toBe([['code' => 'EXE', 'name' => 'Exécutive', 'rooms_free' => 3]]);

        $past = json_decode((string) (new CheckAvailability($context))->handle(new Request(['check_in' => '2026-10-01', 'check_out' => '2026-10-02'])), true);
        expect($past['error'])->toContain('past');

        expect(collect($context->calls())->pluck('tool')->all())->toBe(['get_quote', 'get_quote', 'check_availability', 'check_availability']);
    });
});

it('gives the night price for all the rooms, so the reply never multiplies', function (): void {
    $this->travelTo('2026-11-01 10:00:00');
    ['hotel' => $hotel] = assistantHotel();

    app(TenantContext::class)->run($hotel, function (): void {
        $context = new ToolContext('fr');
        $quote = json_decode((string) (new GetQuote($context))->handle(new Request(['category_code' => 'EXE', 'check_in' => '2026-11-12', 'check_out' => '2026-11-13', 'adults' => 4, 'rooms' => 2])), true);

        expect($quote['rooms'])->toBe(2)
            ->and($quote['nights'][0])->toHaveKeys(['price_per_room', 'price_all_rooms'])
            ->and($context->allowsAmount('25000'))->toBeTrue()
            ->and($context->allowsAmount('50000'))->toBeTrue();

        $single = json_decode((string) (new GetQuote($context))->handle(new Request(['category_code' => 'EXE', 'check_in' => '2026-11-12', 'check_out' => '2026-11-13', 'adults' => 2])), true);
        expect($single['nights'][0])->not->toHaveKey('price_all_rooms');
    });
});

it('offers the pay-now price by bank transfer alone, and says how the guest can pay', function (): void {
    $this->travelTo('2026-11-01 10:00:00');
    ['hotel' => $hotel] = assistantHotel();

    app(TenantContext::class)->run($hotel, function (): void {
        App\Domains\Ai\Models\HotelAssistantSettings::current()->forceFill([
            'deposit_online' => true,
            'prepay_discount_percent' => 10,
            'bank_transfer' => ['enabled' => true, 'account_holder' => 'Hôtel SARL', 'account_number' => 'CI008 0001'],
        ])->save();

        $quote = json_decode((string) (new GetQuote(new ToolContext('fr')))->handle(new Request(['category_code' => 'EXE', 'check_in' => '2026-11-12', 'check_out' => '2026-11-13', 'adults' => 2])), true);

        expect($quote['payment_options'])->not->toBeNull()
            ->and($quote['payment_options']['pay_now_ways'])->toBe(['bank transfer'])
            ->and($quote['payment_options']['pay_now_discount_percent'])->toBe(10);
    });
});

it('forgets an earlier price once the stay is priced again in the same reply', function (): void {
    $context = new ToolContext('fr');
    $context->allowAmountsIn('La Suite pour 2 adultes : 60 000 XOF la nuit.');
    $sixty = Amounts::find('60 000 XOF')[0]['normalised'];

    expect($context->allowsAmount($sixty))->toBeTrue();

    // The guest now says 3 adults: get_quote runs again; only its figures count.
    $context->log('get_quote', ['adults' => 3], '{"total":"75 000 XOF"}');

    expect($context->allowsAmount($sixty))->toBeFalse();
});

it('keeps rooms for reception from the WhatsApp assistant until they are released', function (): void {
    $this->travelTo('2026-11-01 10:00:00');
    ['hotel' => $hotel, 'exe' => $exe] = assistantHotel();

    app(TenantContext::class)->run($hotel, function () use ($exe): void {
        $exe->update(['assistant_keep_back' => 1, 'keep_back_release_days' => 2]);
        $quote = fn (string $in, string $out, int $rooms): array => json_decode((string) (new GetQuote(new ToolContext('fr')))->handle(new Request(['category_code' => 'EXE', 'check_in' => $in, 'check_out' => $out, 'adults' => $rooms, 'rooms' => $rooms])), true);
        $channel = app(App\Domains\Availability\SalesChannel::class);
        $codes = fn (array $q): array => array_column($q['problems'] ?? [], 'code');

        // 3 Executive rooms, 1 kept: the assistant sells 2 in ten days' time…
        expect($codes($channel->asAssistant(fn () => $quote('2026-11-12', '2026-11-13', 2))))->not->toContain('kept_for_reception')
            ->and($codes($channel->asAssistant(fn () => $quote('2026-11-12', '2026-11-13', 3))))->toContain('kept_for_reception')
            // …staff sell all 3…
            ->and($codes($quote('2026-11-12', '2026-11-13', 3)))->not->toContain('kept_for_reception')->not->toContain('not_available')
            // …and two days before arrival the kept room opens to the assistant.
            ->and($codes($channel->asAssistant(fn () => $quote('2026-11-02', '2026-11-03', 3))))->not->toContain('kept_for_reception');
    });
});
