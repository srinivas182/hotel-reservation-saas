<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Plan;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Launch\LaunchChecklist;
use App\Domains\Launch\LaunchWatch;
use App\Domains\Launch\Models\LaunchItem;
use App\Domains\Site\Models\BrandSettings;
use App\Domains\Support\Models\SupportSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('applies launch defaults once, without overwriting choices already made', function (): void {
    $this->artisan('launch:defaults')->assertSuccessful();

    $pro = Plan::query()->where('code', 'pro')->sole();
    expect($pro->price('XOF', 'monthly'))->toBe(45000)->and($pro->price('XAF', 'monthly'))->toBe(45000)
        ->and($pro->price('GHS', 'monthly'))->toBe(115000)->and($pro->price('NGN', 'yearly'))->toBe(115000000)
        ->and(SupportSettings::current()->hoursFor('complet'))->toBe(4)
        ->and(BrandSettings::current()->support_hours['fr'])->toContain('lundi au samedi');

    $pro->update(['prices' => [...$pro->prices, 'GHS' => ['monthly' => 99900, 'yearly' => 999000]]]);
    SupportSettings::current()->update(['response_hours' => ['default' => 12]]);
    $this->artisan('launch:defaults')->assertSuccessful();
    expect($pro->fresh()->price('GHS', 'monthly'))->toBe(99900)->and(SupportSettings::current()->hoursFor('pro'))->toBe(12)
        ->and(config('launch.invoice_retention_years'))->toBe(10);
});

it('asks the owner first before support sessions in new hotels', function (): void {
    $hotel = Hotel::factory()->create();

    expect(app(TenantContext::class)->run($hotel, fn () => HotelSettings::current()->support_access))->toBe('ask_first');
});

it('checks what it can automatically and needs proof for the rest', function (): void {
    $checklist = app(LaunchChecklist::class);
    $checklist->refresh();
    expect(LaunchItem::query()->where('key', 'help_centre')->value('status'))->toBe('done')
        ->and(LaunchItem::query()->where('key', 'payment_live')->value('status'))->toBe('open');

    $finance = User::factory()->platformAdmin(PlatformRole::Finance)->withTwoFactor()->create();
    $sales = User::factory()->platformAdmin(PlatformRole::Sales)->withTwoFactor()->create();
    $issuing = LaunchItem::query()->where('key', 'issuing_company')->sole();

    $this->actingAs($finance)->put(route('platform.launch.items.update', $issuing), ['status' => 'done'])->assertSessionHasErrors('proof');
    $this->actingAs($finance)->put(route('platform.launch.items.update', $issuing), ['status' => 'done', 'proof' => 'RCCM CI-ABJ-2026-B-1234'])->assertSessionHasNoErrors();
    expect($issuing->fresh()->done_by)->toBe($finance->id);

    $this->actingAs($sales)->put(route('platform.launch.items.update', $issuing), ['status' => 'open'])->assertForbidden();
    $this->actingAs($finance)->put(route('platform.launch.items.update', LaunchItem::query()->where('key', 'payment_live')->sole()), ['status' => 'done', 'proof' => 'x'])->assertStatus(422);
    $this->actingAs($finance)->put(route('platform.launch.items.update', $issuing), ['status' => 'not_needed'])->assertForbidden();

    $this->actingAs($sales)->get(route('platform.launch'))->assertOk()->assertInertia(fn ($page) => $page->where('score.ready', false)->has('watch.series.signups'));
});

it('refuses 1.0.0 until every required item is done', function (): void {
    $this->artisan('launch:check')->assertFailed();

    LaunchItem::query()->where('required', true)->update(['status' => 'done']);
    // Automatic items are re-checked: mark those as not needed for this test.
    LaunchItem::query()->whereIn('key', array_keys(LaunchChecklist::automatic()))->update(['status' => 'not_needed']);
    $this->artisan('launch:check')->assertSuccessful();
});

it('counts launch-week activity by hour without personal data', function (): void {
    $this->travelTo('2026-12-01 10:30:00');
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

    $watch = LaunchWatch::build(1);
    expect($watch['hours'])->toHaveCount(24)->and($watch['totals']['failed_jobs'])->toBe(1)->and(end($watch['series']['failed_jobs']))->toBe(1);
});
