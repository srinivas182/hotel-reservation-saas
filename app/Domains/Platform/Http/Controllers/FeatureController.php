<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Billing\Models\Plan;
use App\Domains\Platform\Features\Feature;
use App\Domains\Platform\Features\FeatureFlag;
use App\Domains\Tenancy\Models\Hotel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class FeatureController extends Controller
{
    public function index(): Response
    {
        $flags = FeatureFlag::query()->get()->keyBy('key');

        return Inertia::render('platform/features', [
            'features' => collect(Feature::cases())->map(function (Feature $f) use ($flags): array {
                $flag = $flags->get($f->value);

                return ['key' => $f->value, 'label' => $f->label(), 'mode' => $flag->mode ?? 'on', 'countries' => $flag->countries ?? [], 'plans' => $flag->plans ?? [], 'hotels' => $flag->hotels ?? []];
            })->values(),
            'countries' => Hotel::query()->distinct()->orderBy('country_code')->pluck('country_code')->values(),
            'plans' => Plan::query()->orderBy('code')->pluck('code')->values(),
            'hotels' => Hotel::query()->orderBy('name')->get(['id', 'name'])->values(),
        ]);
    }

    public function update(Request $request, string $feature, #[CurrentUser] User $user): RedirectResponse
    {
        $key = Feature::tryFrom($feature) ?? abort(404);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['on', 'off', 'targeted'])],
            'countries' => ['array'], 'countries.*' => ['string', 'size:2'],
            'plans' => ['array'], 'plans.*' => ['string', 'max:40'],
            'hotels' => ['array'], 'hotels.*' => ['string', Rule::exists('hotels', 'id')],
        ]);

        FeatureFlag::query()->updateOrCreate(['key' => $key->value], [
            'mode' => $data['mode'], 'countries' => array_values(array_map('strtoupper', (array) ($data['countries'] ?? []))),
            'plans' => array_values((array) ($data['plans'] ?? [])), 'hotels' => array_values((array) ($data['hotels'] ?? [])),
        ]);
        Audit::record('platform.feature_changed', 'Feature switch changed', causer: $user, properties: ['feature' => $key->value, ...$data]);

        return back();
    }
}
