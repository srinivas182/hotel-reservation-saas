<?php

declare(strict_types=1);

use App\Domains\Onboarding\Listing\ListingDraft;
use App\Domains\Onboarding\Listing\MapsLink;
use App\Domains\Onboarding\Listing\PageReader;
use App\Domains\Onboarding\Listing\SafeFetcher;

it('reads the map pin and place name from Google Maps links', function (string $url, ?float $lat, ?float $lng, ?string $name): void {
    $pin = MapsLink::parse($url);

    if ($lat === null) {
        expect($pin)->toBeNull();

        return;
    }

    expect($pin)->not->toBeNull()
        ->and($pin['latitude'])->toBe($lat)
        ->and($pin['longitude'])->toBe($lng)
        ->and($pin['name'])->toBe($name);
})->with([
    'place with exact pin' => ['https://www.google.com/maps/place/First+Class+Lodge/@9.4580,-5.6290,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x1!8m2!3d9.4581234!4d-5.6295678', 9.4581234, -5.6295678, 'First Class Lodge'],
    'map centre only' => ['https://www.google.com/maps/@5.3364,-4.0267,15z', 5.3364, -4.0267, null],
    'search query' => ['https://www.google.com/maps/search/?api=1&query=5.3601,-3.9871', 5.3601, -3.9871, null],
    'country domain' => ['https://www.google.ci/maps/place/H%C3%B4tel+Ivoire/@5.33,-4.00,17z', 5.33, -4.0, 'Hôtel Ivoire'],
    'short link (expanded first)' => ['https://maps.app.goo.gl/AbCdEf123', null, null, null],
    'not Google' => ['https://evil.example/maps/@5.3,-4.0,15z', null, null, null],
    'impossible coordinates' => ['https://www.google.com/maps/@95.0,-4.0,15z', null, null, null],
]);

it('only treats Google hosts as Maps links', function (): void {
    expect(MapsLink::isMapsLink('https://maps.google.com/?q=5.3,-4.0'))->toBeTrue()
        ->and(MapsLink::isMapsLink('https://maps.app.goo.gl/x'))->toBeTrue()
        ->and(MapsLink::isMapsLink('https://google.com.evil.example/maps'))->toBeFalse()
        ->and(MapsLink::isMapsLink('https://notgoogle.com/maps'))->toBeFalse();
});

it('reads the structured hotel data and the text of a page', function (): void {
    $html = <<<'HTML'
    <html><head><title>Hôtel Le Baobab – Korhogo</title>
    <meta name="description" content="Hôtel familial au cœur de Korhogo.">
    <script type="application/ld+json">{"@context":"https://schema.org","@graph":[
      {"@type":"Organization","name":"Groupe Baobab"},
      {"@type":["Hotel"],"name":"Hôtel Le Baobab","telephone":"+225 27 36 86 00 00",
       "address":{"@type":"PostalAddress","streetAddress":"Avenue de la Paix","addressLocality":"Korhogo"},
       "geo":{"latitude":"9.45","longitude":"-5.63"},"checkinTime":"T14:00:00","checkoutTime":"12:00",
       "amenityFeature":[{"name":"Wi-Fi"},{"name":"Piscine"}]}]}</script>
    <style>.x{color:red}</style><script>var tracking = 1;</script></head>
    <body><nav>Accueil</nav><h1>Bienvenue</h1><p>Chambre Standard : 25 000 FCFA</p></body></html>
    HTML;

    $read = PageReader::read($html);

    expect($read['title'])->toBe('Hôtel Le Baobab – Korhogo')
        ->and($read['description'])->toBe('Hôtel familial au cœur de Korhogo.')
        ->and($read['structured'])->toMatchArray(['name' => 'Hôtel Le Baobab', 'street' => 'Avenue de la Paix', 'city' => 'Korhogo', 'latitude' => 9.45, 'longitude' => -5.63, 'check_in_time' => '14:00', 'check_out_time' => '12:00', 'amenities' => ['Wi-Fi', 'Piscine']])
        ->and($read['text'])->toContain('Chambre Standard : 25 000 FCFA')
        ->and($read['text'])->not->toContain('tracking')
        ->and($read['text'])->not->toContain('color:red');
});

it('reads times written in different ways', function (): void {
    expect(PageReader::time('14h00'))->toBe('14:00')
        ->and(PageReader::time('T09:30:00'))->toBe('09:30')
        ->and(PageReader::time('2 PM'))->toBe('14:00')
        ->and(PageReader::time('12 am'))->toBe('00:00')
        ->and(PageReader::time('midi'))->toBeNull()
        ->and(PageReader::time('25:00'))->toBeNull();
});

it('follows robots.txt the way search engines do', function (): void {
    $robots = "User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow: /admin\nDisallow: /*.pdf$\nAllow: /admin/public\n";

    expect(SafeFetcher::disallowed($robots, '/'))->toBeFalse()
        ->and(SafeFetcher::disallowed($robots, '/admin/settings'))->toBeTrue()
        ->and(SafeFetcher::disallowed($robots, '/admin/public/rooms'))->toBeFalse()
        ->and(SafeFetcher::disallowed($robots, '/tarifs.pdf'))->toBeTrue()
        ->and(SafeFetcher::disallowed("User-agent: AssistantHotelBot\nDisallow: /\n", '/chambres'))->toBeTrue()
        ->and(SafeFetcher::disallowed('', '/anything'))->toBeFalse();
});

it('refuses addresses that are not on the public internet', function (string $ip, bool $public): void {
    expect(SafeFetcher::isPublic($ip))->toBe($public);
})->with([
    ['93.184.216.34', true],
    ['2606:2800:220:1:248:1893:25c8:1946', true],
    ['127.0.0.1', false],
    ['10.0.0.5', false],
    ['172.16.3.4', false],
    ['192.168.1.1', false],
    ['169.254.169.254', false], // cloud metadata
    ['100.64.0.1', false], // carrier NAT
    ['0.0.0.0', false],
    ['::1', false],
    ['fd00::1', false],
    ['::ffff:127.0.0.1', false],
]);

it('cleans and caps the AI answer, and prefers what the hotel published itself', function (): void {
    $ai = "Voici:\n```json\n".json_encode([
        'profile' => ['name' => ['value' => 'Baobab', 'sure' => false], 'check_in_time' => ['value' => '2 PM', 'sure' => true], 'city' => ['value' => '<b>Korhogo</b>']],
        'rooms' => [
            ['name' => 'Standard', 'count' => 10, 'adults' => 2, 'price' => '25 000', 'sure' => true],
            ['name' => 'Suite', 'count' => 9999, 'adults' => 40, 'price' => -5],
            ['name' => '', 'count' => 1],
        ],
        'entries' => [
            ['locale' => 'fr', 'section' => 'parking', 'question' => 'Parking ?', 'content' => 'Gratuit.'],
            ['locale' => 'de', 'section' => 'parking', 'content' => 'Kostenlos.'],
            ['locale' => 'en', 'section' => 'spa', 'content' => 'Unknown section.'],
        ],
    ])."\n```";

    $draft = ListingDraft::build($ai, ['name' => 'Hôtel Le Baobab'], ['latitude' => 9.45, 'longitude' => -5.63, 'name' => 'Ignored'], ['A note']);

    expect($draft['profile']['name'])->toBe(['value' => 'Hôtel Le Baobab', 'sure' => true])
        ->and($draft['profile']['check_in_time'])->toBe(['value' => '14:00', 'sure' => true])
        ->and($draft['profile']['city']['value'])->toBe('Korhogo')
        ->and($draft['profile']['location'])->toBe(['value' => ['latitude' => 9.45, 'longitude' => -5.63], 'sure' => true])
        ->and(array_keys($draft['profile']))->toBe(['name', 'city', 'check_in_time', 'location'])
        ->and($draft['rooms'])->toBe([
            ['name' => 'Standard', 'count' => 10, 'adults' => 2, 'price' => 25000, 'sure' => true],
            ['name' => 'Suite', 'count' => null, 'adults' => 10, 'price' => null, 'sure' => true],
        ])
        ->and($draft['entries'])->toHaveCount(1)
        ->and($draft['notes'])->toBe(['A note'])
        ->and(ListingDraft::isEmpty(ListingDraft::build('not json', [], null)))->toBeTrue();
});
