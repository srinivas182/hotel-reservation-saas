<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Conversations\MessageStatus;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Messaging\Usage\UsageRecord;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    configureMeta();
    $this->travelTo('2026-11-12 10:00:00');
    $this->hotel = Hotel::factory()->create(['name' => 'First Class Lodge']);
    $this->context = app(TenantContext::class);
    $this->in = fn (callable $callback) => $this->context->run($this->hotel, $callback);
    ($this->in)(fn () => HotelSettings::current()->update(['enabled_locales' => ['fr', 'en']]));
    ($this->in)(fn () => WhatsAppAccount::query()->create([
        'waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 'hotel-token',
    ]));
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
    $this->metaTemplate = ['body' => ['id' => 'tpl-1', 'status' => 'PENDING', 'category' => 'UTILITY'], 'status' => 200];
    Http::fake([
        'graph.facebook.com/v23.0/9000/message_templates*' => fn () => Http::response($this->metaTemplate['body'], $this->metaTemplate['status']),
        'graph.facebook.com/v23.0/5550001/messages' => Http::response(['messages' => [['id' => 'wamid.T1']]]),
    ]);
});

function approvedTemplate(object $test): MessageTemplate
{
    return ($test->in)(fn () => MessageTemplate::query()->create([
        'starter_key' => 'message_received', 'name' => 'message_received', 'language' => 'fr', 'category' => 'UTILITY',
        'body' => 'Bonjour {{1}}, merci pour votre message à {{2}}.', 'examples' => ['Awa', 'Lodge'],
        'status' => TemplateStatus::Approved, 'meta_template_id' => 'tpl-1',
    ]));
}

it('creates a template from the starter library and submits it to Meta', function (): void {
    asStaffOf($this->hotel, $this->owner)
        ->post(route('hotel.whatsapp.templates.store'), ['starter' => 'message_received', 'language' => 'fr'])
        ->assertSessionHasNoErrors();

    $template = ($this->in)(fn () => MessageTemplate::query()->sole());
    expect($template->status)->toBe(TemplateStatus::Pending)
        ->and($template->meta_template_id)->toBe('tpl-1')
        ->and($template->placeholders())->toBe(2);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/9000/message_templates')
        && $request['name'] === 'message_received'
        && $request['language'] === 'fr'
        && $request['category'] === 'UTILITY'
        && $request['components'][0]['example']['body_text'] === [['Awa', 'First Class Lodge']]);
});

it('records Meta\'s refusal and allows resubmitting', function (): void {
    $this->metaTemplate = ['body' => ['error' => ['message' => 'Invalid parameter', 'code' => 100]], 'status' => 400];

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.whatsapp.templates.store'), ['starter' => 'message_received', 'language' => 'en']);

    $template = ($this->in)(fn () => MessageTemplate::query()->sole());
    expect($template->status)->toBe(TemplateStatus::Rejected)->and($template->rejection_reason)->toBe('Invalid parameter');

    $this->metaTemplate = ['body' => ['id' => 'tpl-2', 'status' => 'PENDING'], 'status' => 200];
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.whatsapp.templates.submit', $template));

    expect(($this->in)(fn () => $template->fresh()->status))->toBe(TemplateStatus::Pending);
});

it('updates template status from Meta\'s webhooks', function (): void {
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.whatsapp.templates.store'), ['starter' => 'message_received', 'language' => 'fr']);

    webhookPost($this, [['field' => 'message_template_status_update', 'value' => [
        'event' => 'APPROVED', 'message_template_id' => 'tpl-1', 'message_template_name' => 'message_received', 'message_template_language' => 'fr', 'reason' => 'NONE',
    ]]])->assertOk();
    expect(($this->in)(fn () => MessageTemplate::query()->sole()->status))->toBe(TemplateStatus::Approved);

    webhookPost($this, [['field' => 'message_template_status_update', 'value' => [
        'event' => 'PAUSED', 'message_template_id' => 'tpl-1', 'reason' => 'Low quality',
    ]]]);
    $template = ($this->in)(fn () => MessageTemplate::query()->sole());
    expect($template->status)->toBe(TemplateStatus::Paused)->and($template->rejection_reason)->toBe('Low quality');
});

it('sends an approved template outside the 24-hour window and counts it', function (): void {
    $template = approvedTemplate($this);
    webhookPost($this, [inbound('wamid.IN1')]);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    $this->travel(30)->hours();
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)
        ->post(route('hotel.inbox.template', $conversation), ['template_id' => $template->id, 'values' => ['Awa', 'First Class Lodge']])
        ->assertSessionHasNoErrors();

    $message = ($this->in)(fn () => Message::query()->where('type', 'template')->sole());
    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($message->body)->toBe('Bonjour Awa, merci pour votre message à First Class Lodge.')
        ->and(($this->in)(fn () => UsageRecord::query()->where('meter', 'whatsapp.template.utility')->sum('quantity')))->toEqual(1);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/5550001/messages')
        && $request['type'] === 'template'
        && $request['template']['name'] === 'message_received'
        && $request['template']['language']['code'] === 'fr'
        && $request['template']['components'][0]['parameters'][1]['text'] === 'First Class Lodge');
});

it('refuses templates that are not approved or missing values', function (): void {
    $template = approvedTemplate($this);
    webhookPost($this, [inbound('wamid.IN1')]);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    $reception = staffMember($this->hotel, SystemRole::Reception);

    asStaffOf($this->hotel, $reception)->post(route('hotel.inbox.template', $conversation), ['template_id' => $template->id, 'values' => ['Awa']])->assertSessionHasErrors('template_id');

    ($this->in)(fn () => $template->update(['status' => TemplateStatus::Pending]));
    asStaffOf($this->hotel, $reception)->post(route('hotel.inbox.template', $conversation), ['template_id' => $template->id, 'values' => ['Awa', 'Lodge']])->assertSessionHasErrors('template_id');
});

it('counts free-text replies once each', function (): void {
    webhookPost($this, [inbound('wamid.IN1')]);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))->post(route('hotel.inbox.reply', $conversation), ['body' => 'Bonjour !']);

    expect(($this->in)(fn () => UsageRecord::query()->where('meter', 'whatsapp.service')->count()))->toBe(1);

    asStaffOf($this->hotel, $this->owner)->get(route('hotel.usage'))->assertInertia(fn ($page) => $page->where('whatsapp.service', 1));
});

it('deletes a template at Meta and locally', function (): void {
    $template = approvedTemplate($this);

    asStaffOf($this->hotel, $this->owner)->delete(route('hotel.whatsapp.templates.destroy', $template))->assertRedirect();

    expect(($this->in)(fn () => MessageTemplate::query()->count()))->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), 'hsm_id=tpl-1'));
});

it('applies the WhatsApp permissions to every role', function (): void {
    webhookPost($this, [inbound('wamid.IN1')]);
    $conversation = ($this->in)(fn () => Conversation::query()->sole());

    $expect = [
        // role => [templates page, inbox, reply]
        [SystemRole::Owner, 200, 200, true],
        [SystemRole::Manager, 403, 200, true],
        [SystemRole::Reception, 403, 200, true],
        [SystemRole::ReadOnly, 403, 200, false],
        [SystemRole::RestaurantBar, 403, 403, false],
        [SystemRole::EventsCoordinator, 403, 403, false],
    ];

    foreach ($expect as [$role, $templates, $inbox, $canReply]) {
        $user = staffMember($this->hotel, $role);
        asStaffOf($this->hotel, $user)->get(route('hotel.whatsapp.templates'))->assertStatus($templates);
        asStaffOf($this->hotel, $user)->get(route('hotel.inbox.show', $conversation))->assertStatus($inbox);

        $reply = asStaffOf($this->hotel, $user)->post(route('hotel.inbox.reply', $conversation), ['body' => 'Test']);
        $canReply ? $reply->assertSessionHasNoErrors() : $reply->assertForbidden();
    }
});
