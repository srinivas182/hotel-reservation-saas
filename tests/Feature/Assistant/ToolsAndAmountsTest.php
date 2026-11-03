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
