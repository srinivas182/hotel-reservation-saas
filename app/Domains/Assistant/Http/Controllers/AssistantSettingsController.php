<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Http\Controllers;

use App\Domains\Ai\AiControls;
use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Knowledge\KnowledgePack;
use App\Domains\Audit\Audit;
use App\Domains\BookingRequests\DecisionMessages;
use App\Domains\GoLive\Readiness;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Hotel\Support\Locales;
use App\Domains\StayMessages\StayMessageKind;
use App\Domains\StayMessages\StayMessenger;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
                'mode' => $settings->mode->value,
                'privacy_url' => $settings->privacy_url ?? '',
                'human_keywords' => implode(', ', $settings->humanKeywords()),
                'display_name' => $settings->display_name ?? '',
                'tone' => $settings->tone,
                'languages' => $settings->languages ?? $enabled,
                'handover_messages' => (object) ($settings->handover_messages ?? []),
                'daily_budget' => $settings->daily_budget_micro_usd / 1_000_000,
                'kill_switch' => $settings->kill_switch,
                'request_hold_minutes' => $settings->request_hold_minutes,
                'deposit_required' => $settings->deposit_required,
                'deposit_percent' => $settings->deposit_percent,
                'deposit_hours' => $settings->deposit_hours,
                'deposit_instructions' => (object) ($settings->deposit_instructions ?? []),
                'decision_messages' => (object) ($settings->decision_messages ?? []),
                'silence_minutes' => $settings->silence_minutes,
                'stay_messages' => StayMessenger::settings($settings),
                'review_url' => $settings->review_url ?? '',
            ],
            'stayKinds' => collect(StayMessageKind::cases())->map(fn (StayMessageKind $k): array => ['value' => $k->value, 'label' => $k->label()])->values(),
            'modes' => collect(AssistantMode::cases())->map(fn (AssistantMode $m): array => ['value' => $m->value, 'label' => $m->label()])->values(),
            'decisionKinds' => DecisionMessages::KINDS,
            'placeholders' => DecisionMessages::PLACEHOLDERS,
            'defaultMessages' => collect($enabled)->mapWithKeys(fn (string $locale): array => [
                $locale => collect(DecisionMessages::KINDS)->mapWithKeys(fn (string $kind): array => [$kind => DecisionMessages::default($kind, $locale)])->all(),
            ])->all(),
            'enabledLocales' => array_map(fn (string $code): array => ['code' => $code, 'label' => Locales::SUPPORTED[$code] ?? $code], $enabled),
            'spentToday' => round($controls->spentToday() / 1_000_000, 2),
            'publishedVersion' => $published?->version,
        ]);
    }

    public function update(Request $request, Readiness $readiness, #[CurrentUser] User $user): RedirectResponse
    {
        $enabled = HotelSettings::current()->enabled_locales ?? [$this->context->hotel()->default_locale];

        $data = $request->validate([
            'mode' => [Rule::requiredIf(! $request->has('enabled')), 'nullable', Rule::enum(AssistantMode::class)],
            'enabled' => ['nullable', 'boolean'],
            'privacy_url' => ['nullable', 'url:https', 'max:300'],
            'human_keywords' => ['nullable', 'string', 'max:300'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'tone' => ['required', Rule::in(['formal', 'friendly'])],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => [Rule::in($enabled)],
            'handover_messages' => ['nullable', 'array'],
            'handover_messages.*' => ['nullable', 'string', 'max:500'],
            'daily_budget' => ['required', 'numeric', 'min:0', 'max:1000'],
            'kill_switch' => ['required', 'boolean'],
            'request_hold_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'deposit_required' => ['required', 'boolean'],
            'deposit_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'deposit_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'deposit_instructions' => ['nullable', 'array'],
            'deposit_instructions.*' => ['nullable', 'string', 'max:300'],
            'decision_messages' => ['nullable', 'array'],
            'decision_messages.*' => ['array'],
            'decision_messages.*.*' => ['nullable', 'string', 'max:900'],
            'silence_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'stay_messages' => ['nullable', 'array'],
            'stay_messages.*.enabled' => ['boolean'],
            'stay_messages.*.time' => ['date_format:H:i'],
            'review_url' => ['nullable', 'url:https', 'max:300'],
        ]);

        if ((bool) $data['deposit_required'] && trim((string) ($data['deposit_instructions'][$this->context->hotel()->default_locale] ?? '')) === '') {
            throw ValidationException::withMessages(['deposit_instructions' => __('Explain how to pay the deposit, at least in the hotel’s main language.')]);
        }

        $settings = HotelAssistantSettings::current();
        $before = Arr::only($settings->attributesToArray(), ['mode', 'kill_switch', 'daily_budget_micro_usd', 'deposit_required', 'deposit_percent']);

        // Older forms send "enabled"; the mode is what counts.
        $mode = AssistantMode::tryFrom((string) ($data['mode'] ?? '')) ?? ((bool) ($data['enabled'] ?? false) ? AssistantMode::Automatic : AssistantMode::Off);

        if ($mode === AssistantMode::Automatic && $settings->mode !== AssistantMode::Automatic && ! app(\App\Domains\Platform\Features\Features::class)->enabled(\App\Domains\Platform\Features\Feature::AutomaticMode)) {
            throw ValidationException::withMessages(['mode' => __('Automatic mode is not available for your hotel yet.')]);
        }

        if ($mode !== AssistantMode::Off && $settings->mode === AssistantMode::Off && $settings->readiness_override_at === null && ! $readiness->requiredMet()) {
            throw ValidationException::withMessages(['mode' => __('Finish the required go-live checks before the assistant answers guests.')]);
        }

        $settings->update([
            'mode' => $mode,
            'enabled' => $mode !== AssistantMode::Off,
            'privacy_url' => $data['privacy_url'] ?? $settings->privacy_url,
            'human_keywords' => array_key_exists('human_keywords', $data)
                ? array_values(array_filter(array_map(fn (string $k): string => trim($k), explode(',', (string) $data['human_keywords']))))
                : $settings->human_keywords,
            'display_name' => $data['display_name'] ?? null,
            'tone' => $data['tone'],
            'languages' => array_values(array_unique($data['languages'])),
            'handover_messages' => array_filter(Arr::only((array) ($data['handover_messages'] ?? []), $enabled), fn ($m): bool => is_string($m) && trim($m) !== ''),
            'daily_budget_micro_usd' => (int) round((float) $data['daily_budget'] * 1_000_000),
            'kill_switch' => (bool) $data['kill_switch'],
            'request_hold_minutes' => (int) $data['request_hold_minutes'],
            'deposit_required' => (bool) $data['deposit_required'],
            'deposit_percent' => (int) $data['deposit_percent'],
            'deposit_hours' => (int) $data['deposit_hours'],
            'deposit_instructions' => array_filter(Arr::only((array) ($data['deposit_instructions'] ?? []), $enabled), fn ($m): bool => is_string($m) && trim($m) !== ''),
            'decision_messages' => collect(Arr::only((array) ($data['decision_messages'] ?? []), $enabled))
                ->map(fn ($kinds): array => array_filter(Arr::only((array) $kinds, DecisionMessages::KINDS), fn ($m): bool => is_string($m) && trim($m) !== ''))
                ->filter()
                ->all(),
            'silence_minutes' => (int) ($data['silence_minutes'] ?? $settings->silence_minutes),
            'stay_messages' => collect(StayMessageKind::cases())->mapWithKeys(fn (StayMessageKind $k): array => [$k->value => [
                'enabled' => (bool) ($data['stay_messages'][$k->value]['enabled'] ?? false),
                'time' => (string) ($data['stay_messages'][$k->value]['time'] ?? $k->defaultTime()),
            ]])->all(),
            'review_url' => $data['review_url'] ?? null,
        ]);

        Audit::record('assistant.settings_updated', 'Assistant settings updated', subject: $this->context->hotel(), causer: $user, properties: [
            'old' => $before,
            'new' => Arr::only($settings->attributesToArray(), ['mode', 'kill_switch', 'daily_budget_micro_usd', 'deposit_required', 'deposit_percent']),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Assistant settings saved.')]);

        return back();
    }
}
