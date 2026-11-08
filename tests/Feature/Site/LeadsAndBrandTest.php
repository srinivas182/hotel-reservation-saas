<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Onboarding\Models\SignupCode;
use App\Domains\Site\Mail\LeadsDigestMail;
use App\Domains\Site\Models\BrandSettings;
use App\Domains\Site\Models\Lead;
use App\Domains\Site\Models\SitePage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Onboarding/helpers.php';

function lead(array $overrides = []): Lead
{
    return Lead::query()->create(['name' => 'Awa', 'hotel_name' => 'Hôtel Bassam', 'whatsapp' => '+2250700000001', 'status' => 'new', ...$overrides]);
}

it('lets sales follow leads and turn them into invitation codes that lead to a trial', function (): void {
    $sales = User::factory()->platformAdmin(PlatformRole::Sales)->withTwoFactor()->create();
    $lead = lead();

    $this->actingAs($sales)->get(route('platform.leads'))->assertOk()->assertInertia(fn ($page) => $page->has('leads', 1)->where('leads.0.hotel_name', 'Hôtel Bassam'));
    $this->actingAs($sales)->put(route('platform.leads.update', $lead), ['status' => 'lost'])->assertSessionHasErrors('lost_reason');
    $this->actingAs($sales)->put(route('platform.leads.update', $lead), ['status' => 'contacted', 'owner_id' => $sales->id, 'follow_up_on' => '2026-11-10', 'notes' => 'Rappeler mardi'])->assertSessionHasNoErrors();
    $this->actingAs($sales)->post(route('platform.leads.convert', $lead))->assertSessionHasNoErrors();

    $lead->refresh();
    $code = SignupCode::query()->sole();
    expect($lead->signup_code_id)->toBe($code->id)->and($lead->status)->toBe('demo_done')->and($code->max_uses)->toBe(1);

    $this->flushSession();
    $this->app['auth']->forgetGuards();
    publishLegalDefaults();
    signupMode('code');
    $this->post(route('signup.store'), signupPayload(['code' => $code->code]));
    expect($lead->fresh()->status)->toBe('trial');

    $this->actingAs(User::factory()->platformAdmin(PlatformRole::Operations)->withTwoFactor()->create())->get(route('platform.leads'))->assertForbidden();
});

it('emails the sales team new leads and follow-ups due', function (): void {
    Mail::fake();
    $this->travelTo('2026-11-08 07:30:00');
    User::factory()->platformAdmin(PlatformRole::Sales)->withTwoFactor()->create(['email' => 'sales@cnh.example']);
    User::factory()->platformAdmin(PlatformRole::Finance)->withTwoFactor()->create(['email' => 'finance@cnh.example']);
    lead();
    lead(['hotel_name' => 'Hôtel Old', 'status' => 'contacted', 'follow_up_on' => '2026-11-07', 'created_at' => now()->subDays(10)]);

    $this->artisan('leads:digest')->assertSuccessful();

    Mail::assertQueued(LeadsDigestMail::class, fn (LeadsDigestMail $m): bool => $m->hasTo('sales@cnh.example') && count($m->new) === 1 && count($m->overdue) === 1);
    Mail::assertNotQueued(LeadsDigestMail::class, fn (LeadsDigestMail $m): bool => $m->hasTo('finance@cnh.example'));
});

it('lets sales edit the pages, and only super admins the brand; logos must be plain pictures', function (): void {
    Storage::fake('local');
    $sales = User::factory()->platformAdmin(PlatformRole::Sales)->withTwoFactor()->create();
    $admin = User::factory()->platformAdmin()->withTwoFactor()->create();
    $page = SitePage::query()->where('key', 'faq')->sole();

    $this->actingAs($sales)->put(route('platform.website.pages.update', $page), ['title' => ['fr' => 'FAQ', 'en' => 'FAQ'], 'description' => ['fr' => 'Réponses', 'en' => 'Answers'], 'body' => ['fr' => '### Nouveau ?', 'en' => '### New?'], 'published' => true])->assertSessionHasNoErrors();
    $this->get('/faq')->assertSee('Nouveau ?');
    $this->actingAs($sales)->post(route('platform.website.brand'), ['name' => 'X', 'primary_color' => '#000000'])->assertForbidden();

    $evil = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $this->actingAs($admin)->post(route('platform.website.brand'), ['name' => 'Réceptio', 'primary_color' => '#0F766E', 'logo' => $evil])->assertSessionHasErrors('logo');

    $png = UploadedFile::fake()->image('logo.png', 128, 128);
    $this->actingAs($admin)->post(route('platform.website.brand'), ['name' => 'Réceptio', 'primary_color' => '#0F766E', 'support_email' => 'aide@reception.example', 'logo' => $png])->assertSessionHasNoErrors();

    $brand = BrandSettings::current();
    expect($brand->name)->toBe('Réceptio')->and($brand->primary_color)->toBe('#0f766e')->and($brand->logo_path)->toStartWith('brand/');
    $this->get(route('brand.logo'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
});
