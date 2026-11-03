<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Http\Controllers;

use App\Domains\Ai\AiControls;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Knowledge\KnowledgePack;
use App\Domains\Audit\Audit;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Hotel\Support\Locales;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AssistantSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(AiControls $controls, KnowledgePack $knowledge): Response
    {
        $settings = HotelAssistantSettings::current();
        $enabled = HotelSettings::current()->enabled_locales ?? [$this->context->hotel()->default_locale];
        $published = $knowledge->published();

        return Inertia::render('hotel/assistant', [
            'settings' => [
                'enabled' => $settings->enabled,
                'display_name' => $settings->display_name ?? '',
                'tone' => $settings->tone,
                'languages' => $settings->languages ?? $enabled,
                'handover_messages' => (object) ($settings->handover_messages ?? []),
                'daily_budget' => $settings->daily_budget_micro_usd / 1_000_000,
                'kill_switch' => $settings->kill_switch,
            ],
            'enabledLocales' => array_map(fn (string $code): array => ['code' => $code, 'label' => Locales::SUPPORTED[$code] ?? $code], $enabled),
            'spentToday' => round($controls->spentToday() / 1_000_000, 2),
            'publishedVersion' => $published?->version,
        ]);
    }

    public function update(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $enabled = HotelSettings::current()->enabled_locales ?? [$this->context->hotel()->default_locale];

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'tone' => ['required', Rule::in(['formal', 'friendly'])],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => [Rule::in($enabled)],
            'handover_messages' => ['nullable', 'array'],
            'handover_messages.*' => ['nullable', 'string', 'max:500'],
            'daily_budget' => ['required', 'numeric', 'min:0', 'max:1000'],
            'kill_switch' => ['required', 'boolean'],
        ]);

        $settings = HotelAssistantSettings::current();
        $before = Arr::only($settings->attributesToArray(), ['enabled', 'kill_switch', 'daily_budget_micro_usd']);

        $settings->update([
            'enabled' => (bool) $data['enabled'],
            'display_name' => $data['display_name'] ?? null,
            'tone' => $data['tone'],
            'languages' => array_values(array_unique($data['languages'])),
            'handover_messages' => array_filter(Arr::only((array) ($data['handover_messages'] ?? []), $enabled), fn ($m): bool => is_string($m) && trim($m) !== ''),
            'daily_budget_micro_usd' => (int) round((float) $data['daily_budget'] * 1_000_000),
            'kill_switch' => (bool) $data['kill_switch'],
        ]);

        Audit::record('assistant.settings_updated', 'Assistant settings updated', subject: $this->context->hotel(), causer: $user, properties: [
            'old' => $before,
            'new' => Arr::only($settings->attributesToArray(), ['enabled', 'kill_switch', 'daily_budget_micro_usd']),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Assistant settings saved.')]);

        return back();
    }
}
