<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Billing\BillingOverview;
use App\Domains\Billing\CreditKind;
use App\Domains\Billing\Credits;
use App\Domains\Billing\Exceptions\PlanChangeNotPossible;
use App\Domains\Billing\Models\BillingSettings;
use App\Domains\Billing\Models\CreditPack;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Models\SubscriptionChange;
use App\Domains\Billing\Module;
use App\Domains\Billing\Subscriptions;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform → Billing: plans, credit packs, AI weights, and each hotel's
 * subscription and credits.
 */
final class PlatformBillingController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        $settings = BillingSettings::current();

        return Inertia::render('platform/billing', [
            'plans' => Plan::query()->orderBy('sort_order')->get()->map(fn (Plan $p): array => [
                'id' => $p->id, 'code' => $p->code, 'name' => (object) $p->name, 'modules' => $p->modules, 'staff_limit' => $p->staff_limit,
                'ai_credits' => $p->ai_credits, 'email_credits' => $p->email_credits, 'prices' => (object) $p->prices, 'trial_days' => $p->trial_days,
                'is_default' => $p->is_default, 'active' => $p->active,
                'hotels' => Subscription::query()->withoutGlobalScopes()->where('plan_id', $p->id)->count(),
            ])->values(),
            'packs' => CreditPack::query()->orderBy('kind')->orderBy('credits')->get()->map(fn (CreditPack $p): array => [
                'id' => $p->id, 'code' => $p->code, 'name' => (object) $p->name, 'kind' => $p->kind->value, 'credits' => $p->credits, 'prices' => (object) $p->prices, 'valid_months' => $p->valid_months, 'active' => $p->active,
            ])->values(),
            'settings' => ['ai_weights' => array_merge(BillingSettings::DEFAULT_WEIGHTS, $settings->ai_weights ?? []), 'buffer_percent' => $settings->buffer_percent],
            'modules' => array_map(fn (Module $m): array => ['value' => $m->value, 'label' => $m->label()], Module::cases()),
            'subscriptions' => Subscription::query()->withoutGlobalScopes()->with('plan')->get()->map(fn (Subscription $s): array => [
                'hotel_id' => $s->hotel_id, 'hotel' => Hotel::query()->find($s->hotel_id)?->name, 'plan' => $s->plan?->code, 'status' => $s->status,
                'period_end' => $s->period_end->toDateString(), 'price_minor' => $s->price_minor, 'currency' => $s->currency,
            ])->values(),
        ]);
    }

    private function savePlan(Request $request, #[CurrentUser] User $user, ?Plan $plan = null): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'array'], 'name.*' => ['nullable', 'string', 'max:80'],
            'modules' => ['array'], 'modules.*' => [Rule::enum(Module::class)],
            'staff_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'ai_credits' => ['required', 'integer', 'min:0', 'max:10000000'],
            'email_credits' => ['required', 'integer', 'min:0', 'max:10000000'],
            'prices' => ['required', 'array', 'min:1'],
            'prices.*.monthly' => ['nullable', 'integer', 'min:0'],
            'prices.*.yearly' => ['nullable', 'integer', 'min:0'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            'is_default' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ]);
        $data['name'] = array_filter((array) $data['name']);
        $data['modules'] = array_values(array_unique((array) ($data['modules'] ?? [])));
        $data['prices'] = array_filter(array_change_key_case((array) $data['prices'], CASE_UPPER), fn ($p): bool => is_array($p) && ($p['monthly'] ?? null) !== null);

        if ((bool) $data['is_default']) {
            Plan::query()->when($plan !== null, fn ($q) => $q->whereKeyNot($plan?->id))->update(['is_default' => false]);
        }

        $plan === null ? $plan = Plan::query()->create($data) : $plan->update($data);
        Audit::record('billing.plan_saved', 'Plan saved', subject: $plan, causer: $user, properties: ['code' => $plan->code]);

        return back();
    }

    public function storePlan(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->savePlan($request, $user);
    }

    public function updatePlan(Request $request, Plan $plan, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->savePlan($request, $user, $plan);
    }

    public function storePack(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->savePack($request, $user);
    }

    public function updatePack(Request $request, CreditPack $pack, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->savePack($request, $user, $pack);
    }

    public function settings(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'ai_weights' => ['required', 'array'],
            'ai_weights.assistant' => ['required', 'integer', 'min:0', 'max:10000'],
            'ai_weights.transcription' => ['required', 'integer', 'min:0', 'max:10000'],
            'ai_weights.translation' => ['required', 'integer', 'min:0', 'max:10000'],
            'buffer_percent' => ['required', 'integer', 'min:0', 'max:50'],
        ]);
        BillingSettings::current()->update($data);
        Audit::record('billing.settings_saved', 'Billing settings saved', causer: $user, properties: $data);

        return back();
    }

    public function hotel(Hotel $hotel): Response
    {
        return $this->context->run($hotel, function () use ($hotel): Response {
            $data = app(BillingOverview::class)->build(app()->getLocale());

            return Inertia::render('platform/hotels/billing', [
                ...$data,
                'hotel' => ['id' => $hotel->id, 'name' => $hotel->name, 'currency' => $hotel->currency],
                'plans' => Plan::query()->where('active', true)->orderBy('sort_order')->get(['id', 'code', 'name', 'prices'])->map(fn (Plan $p): array => ['id' => $p->id, 'code' => $p->code, 'currencies' => array_keys($p->prices)])->values(),
                'packsForSale' => CreditPack::query()->where('active', true)->get()->map(fn (CreditPack $p): array => ['id' => $p->id, 'code' => $p->code, 'kind' => $p->kind->value, 'credits' => $p->credits])->values(),
                'changes' => SubscriptionChange::query()->latest('id')->limit(30)->get()->map(fn (SubscriptionChange $c): array => [
                    'kind' => $c->kind, 'amount_minor' => $c->amount_minor, 'currency' => $c->currency, 'at' => $c->created_at?->toDateTimeString(), 'invoiced' => $c->invoiced_at !== null,
                ])->values(),
            ]);
        });
    }

    public function subscribe(Request $request, Hotel $hotel, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'string', Rule::exists('plans', 'id')],
            'interval' => ['required', Rule::in(['monthly', 'yearly'])],
            'currency' => ['required', 'string', 'size:3'],
            'trial' => ['required', 'boolean'],
        ]);

        return $this->attempt($hotel, function () use ($data, $user): void {
            $subscription = Subscription::query()->with('plan')->first();
            $plan = Plan::query()->findOrFail((string) $data['plan_id']);
            $subscription === null
                ? app(Subscriptions::class)->start($plan, (string) $data['interval'], strtoupper((string) $data['currency']), (bool) $data['trial'], $user)
                : app(Subscriptions::class)->changePlan($subscription, $plan, $user);
        });
    }

    public function extendTrial(Request $request, Hotel $hotel, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:60']]);

        return $this->attempt($hotel, fn () => app(Subscriptions::class)->extendTrial(Subscription::query()->firstOrFail(), (int) $data['days'], $user));
    }

    public function adjust(Request $request, Hotel $hotel, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(CreditKind::class)],
            'credits' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'reason' => ['required', 'string', 'max:300'],
        ]);

        return $this->attempt($hotel, fn () => app(Credits::class)->adjust(CreditKind::from((string) $data['kind']), (int) $data['credits'], (string) $data['reason'], $user));
    }

    public function addPack(Request $request, Hotel $hotel, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['pack_id' => ['required', 'integer', Rule::exists('credit_packs', 'id')]]);

        return $this->attempt($hotel, fn () => app(Subscriptions::class)->addPack(CreditPack::query()->findOrFail((int) $data['pack_id']), $user));
    }

    private function savePack(Request $request, User $user, ?CreditPack $pack = null): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('credit_packs', 'code')->ignore($pack?->id)],
            'name' => ['required', 'array'], 'name.*' => ['nullable', 'string', 'max:80'],
            'kind' => ['required', Rule::enum(CreditKind::class)],
            'credits' => ['required', 'integer', 'min:1', 'max:10000000'],
            'prices' => ['required', 'array', 'min:1'], 'prices.*' => ['required', 'integer', 'min:0'],
            'valid_months' => ['required', 'integer', 'min:1', 'max:36'],
            'active' => ['required', 'boolean'],
        ]);
        $data['name'] = array_filter((array) $data['name']);
        $data['prices'] = array_change_key_case((array) $data['prices'], CASE_UPPER);
        $pack === null ? $pack = CreditPack::query()->create($data) : $pack->update($data);
        Audit::record('billing.pack_saved', 'Credit pack saved', subject: $pack, causer: $user, properties: ['code' => $pack->code]);

        return back();
    }

    private function attempt(Hotel $hotel, callable $action): RedirectResponse
    {
        try {
            $this->context->run($hotel, $action);
        } catch (PlanChangeNotPossible $e) {
            throw ValidationException::withMessages(['plan' => $e->getMessage()]);
        }

        return back();
    }
}
