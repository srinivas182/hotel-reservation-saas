<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Engine\HotelAssistantAgent;
use App\Domains\Assistant\Knowledge\KnowledgeEntry;
use App\Domains\Assistant\Knowledge\KnowledgePack;
use App\Domains\Assistant\Knowledge\KnowledgeVersion;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\TenantContext;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-01 10:00:00');
    ['hotel' => $this->hotel] = assistantHotel();
    $this->manager = staffMember($this->hotel, SystemRole::Manager);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
});

it('saves the assistant settings', function (): void {
    asStaffOf($this->hotel, $this->manager)->put(route('hotel.assistant.update'), [
        'mode' => 'off', 'display_name' => 'Awa, l’assistante', 'tone' => 'formal', 'languages' => ['fr'],
        'handover_messages' => ['fr' => 'La réception vous répond.', 'en' => ''], 'daily_budget' => 7.5, 'kill_switch' => false,
        'request_hold_minutes' => 120, 'deposit_required' => false, 'deposit_percent' => 30, 'deposit_hours' => 24,
    ])->assertSessionHasNoErrors();

    $settings = ($this->in)(fn () => HotelAssistantSettings::current());
    expect($settings->daily_budget_micro_usd)->toBe(7_500_000)
        ->and($settings->languages)->toBe(['fr'])
        ->and($settings->handover_messages)->toBe(['fr' => 'La réception vous répond.'])
        ->and(AuditLog::query()->where('event', 'assistant.settings_updated')->exists())->toBeTrue();

    asStaffOf($this->hotel, $this->manager)->put(route('hotel.assistant.update'), [
        'mode' => 'off', 'tone' => 'formal', 'languages' => ['de'], 'daily_budget' => 5, 'kill_switch' => false,
    ])->assertSessionHasErrors('languages.0');
});

it('edits the draft and only uses it once published', function (): void {
    asStaffOf($this->hotel, $this->manager)->put(route('hotel.assistant.knowledge.update'), [
        'locale' => 'fr',
        'entries' => [
            ['section' => 'parking', 'content' => 'Parking gratuit et gardé.'],
            ['section' => 'faq', 'question' => 'Avez-vous le wifi ?', 'content' => 'Oui, gratuit dans tout l’hôtel.'],
        ],
    ])->assertSessionHasNoErrors();

    $compiled = fn () => ($this->in)(fn () => app(KnowledgePack::class)->compile('fr'));
    expect($compiled())->not->toContain('Parking gratuit');

    asStaffOf($this->hotel, $this->manager)->post(route('hotel.assistant.knowledge.publish'));

    expect($compiled())->toContain('Parking gratuit')
        ->and($compiled())->toContain("Q: Avez-vous le wifi ?\nA: Oui, gratuit")
        ->and(($this->in)(fn () => KnowledgeVersion::query()->max('version')))->toBe(2)
        ->and(AuditLog::query()->where('event', 'assistant.knowledge_published')->count())->toBe(2);
});

it('requires a question for questions and answers', function (): void {
    asStaffOf($this->hotel, $this->manager)->put(route('hotel.assistant.knowledge.update'), [
        'locale' => 'fr', 'entries' => [['section' => 'faq', 'question' => '', 'content' => 'Réponse']],
    ])->assertSessionHasErrors('entries.0.question');
});

it('falls back to the default language when the pack has no entries in the guest\'s', function (): void {
    ($this->in)(function (): void {
        KnowledgeEntry::query()->where('locale', 'en')->delete();
        app(KnowledgePack::class)->publish();
    });

    expect(($this->in)(fn () => app(KnowledgePack::class)->compile('en')))->toContain('Orange Money');
});

it('runs the practice screen without sending anything', function (): void {
    HotelAssistantAgent::fake([aiReply('Bonjour ! Comment puis-je vous aider ?', ['intent' => 'greeting'])]);

    asStaffOf($this->hotel, $this->manager)
        ->post(route('hotel.assistant.practice.run'), ['turns' => [['role' => 'guest', 'text' => 'Bonjour']]])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data', fn (array $flash): bool => $flash['practice']['reply'] === 'Bonjour ! Comment puis-je vous aider ?'
            && $flash['practice']['handover'] === false);
});

it('keeps the assistant pages for people who manage it', function (): void {
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)->get(route('hotel.assistant'))->assertForbidden();
    asStaffOf($this->hotel, $reception)->post(route('hotel.assistant.practice.run'), ['turns' => [['role' => 'guest', 'text' => 'x']]])->assertForbidden();
    asStaffOf($this->hotel, $this->manager)->get(route('hotel.assistant'))->assertOk()->assertInertia(fn ($page) => $page->where('publishedVersion', 1));
    asStaffOf($this->hotel, $this->manager)->get(route('hotel.assistant.knowledge', ['locale' => 'fr']))->assertOk()->assertInertia(fn ($page) => $page->has('entries', 1));
});
