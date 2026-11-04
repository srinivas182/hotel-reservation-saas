<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Ai\Agents\PingAgent;
use App\Domains\Ai\AiDriver;
use App\Domains\Ai\Evals\AiEvalRun;
use App\Domains\Ai\Evals\RunEvalSuite;
use App\Domains\Ai\Gateway\ProviderRegistry;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiPlatformSettings;
use App\Domains\Ai\Models\AiProvider;
use App\Domains\Ai\Models\AiRun;
use App\Domains\Ai\ModelStatus;
use App\Domains\Audit\Audit;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Platform AI: providers (MCS keys), the approved model list and which
 * models each task uses. Super admins only.
 */
final class AiController extends Controller
{
    public function index(#[CurrentUser] User $user, TenantContext $context): Response
    {
        $this->authorizeSuperAdmin($user);
        $settings = AiPlatformSettings::current();

        $monthCost = $context->bypass('Platform AI cost summary', fn (): int => (int) AiRun::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_micro_usd'));

        return Inertia::render('platform/ai', [
            'providers' => AiProvider::query()->orderBy('name')->get()->map(fn (AiProvider $provider): array => [
                'id' => $provider->id,
                'name' => $provider->name,
                'driver' => $provider->driver->value,
                'driver_label' => $provider->driver->label(),
                'url' => $provider->credentials['url'] ?? null,
                'key_hint' => isset($provider->credentials['key']) ? '…'.substr((string) $provider->credentials['key'], -4) : null,
                'active' => $provider->active,
            ])->values(),
            'models' => AiModel::query()->with('provider')->orderBy('label')->get()->map(fn (AiModel $model): array => [
                'id' => $model->id,
                'ai_provider_id' => $model->ai_provider_id,
                'provider' => $model->provider?->name,
                'model' => $model->model,
                'label' => $model->label,
                'capabilities' => $model->capabilities,
                'status' => $model->status->value,
                'status_label' => $model->status->label(),
                'input_cost' => $model->input_cost_per_mtok / 1_000_000,
                'output_cost' => $model->output_cost_per_mtok / 1_000_000,
                'audio_cost' => $model->audio_cost_per_minute / 1_000_000,
                'certified_at' => $model->certified_at?->toDateString(),
            ])->values(),
            'settings' => [
                'assistant_model_id' => $settings->assistant_model_id,
                'assistant_fallback_id' => $settings->assistant_fallback_id,
                'transcription_model_id' => $settings->transcription_model_id,
                'transcription_fallback_id' => $settings->transcription_fallback_id,
                'timeout_seconds' => $settings->timeout_seconds,
                'kill_switch' => $settings->kill_switch,
            ],
            'drivers' => collect(AiDriver::cases())->map(fn (AiDriver $d): array => ['value' => $d->value, 'label' => $d->label(), 'needs_url' => $d->needsUrl()])->values(),
            'statuses' => collect(ModelStatus::cases())->map(fn (ModelStatus $s): array => ['value' => $s->value, 'label' => $s->label()])->values(),
            'monthCost' => round($monthCost / 1_000_000, 2),
            'evalRuns' => AiEvalRun::query()->with('model')->orderByDesc('id')->limit(10)->get()->map(fn (AiEvalRun $run): array => [
                'id' => $run->id,
                'model' => $run->model?->label,
                'started_at' => $run->started_at->format('Y-m-d H:i'),
                'finished' => $run->finished_at !== null,
                'passed' => $run->passed,
                'total' => $run->total,
                'safety_passed' => $run->safety_passed,
                'safety_total' => $run->safety_total,
                'meets_threshold' => $run->meets_threshold,
                'cost' => round($run->cost_micro_usd / 1_000_000, 4),
                'failures' => array_values(array_map(
                    fn (array $r): array => ['id' => $r['id'], 'safety' => $r['safety'], 'failures' => $r['failures'], 'reply' => $r['reply'] ?? null],
                    array_filter((array) $run->results, fn (array $r): bool => ! $r['passed']),
                )),
            ])->values(),
        ]);
    }

    public function storeProvider(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin($user);
        $data = $this->validateProvider($request, true);

        $provider = AiProvider::query()->create([
            'name' => $data['name'],
            'driver' => $data['driver'],
            'credentials' => array_filter(['key' => $data['key'], 'url' => $data['url'] ?? null]),
            'active' => (bool) $data['active'],
        ]);

        Audit::record('ai.provider_created', 'AI provider added', subject: $provider, causer: $user, properties: ['name' => $provider->name, 'driver' => $provider->driver->value]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI provider saved.')]);

        return back();
    }

    public function updateProvider(Request $request, AiProvider $aiProvider, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin($user);
        $data = $this->validateProvider($request, false);

        $credentials = $aiProvider->credentials;

        if (filled($data['key'] ?? null)) {
            $credentials['key'] = (string) $data['key'];
        }

        $credentials['url'] = $data['url'] ?? null;

        $aiProvider->update([
            'name' => $data['name'],
            'driver' => $data['driver'],
            'credentials' => array_filter($credentials),
            'active' => (bool) $data['active'],
        ]);

        Audit::record('ai.provider_updated', 'AI provider updated', subject: $aiProvider, causer: $user, properties: [
            'name' => $aiProvider->name,
            'key_changed' => filled($data['key'] ?? null),
            'active' => $aiProvider->active,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI provider saved.')]);

        return back();
    }

    public function destroyProvider(AiProvider $aiProvider, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin($user);

        Audit::record('ai.provider_deleted', 'AI provider removed', subject: $aiProvider, causer: $user, properties: ['name' => $aiProvider->name]);
        $aiProvider->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI provider removed.')]);

        return back();
    }

    public function storeModel(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin($user);

        $model = AiModel::query()->create($this->validateModel($request));

        Audit::record('ai.model_created', 'AI model added', subject: $model, causer: $user, properties: ['model' => $model->model]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI model saved.')]);

        return back();
    }

    public function updateModel(Request $request, AiModel $aiModel, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin($user);

        $before = $aiModel->status;
        $aiModel->update($this->validateModel($request, $aiModel));

        Audit::record('ai.model_updated', 'AI model updated', subject: $aiModel, causer: $user, properties: [
            'model' => $aiModel->model,
            'old_status' => $before->value,
            'status' => $aiModel->status->value,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI model saved.')]);

        return back();
    }

    public function destroyModel(AiModel $aiModel, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin($user);

        Audit::record('ai.model_deleted', 'AI model removed', subject: $aiModel, causer: $user, properties: ['model' => $aiModel->model]);
        $aiModel->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI model removed.')]);

        return back();
    }

    public function updateSettings(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin($user);

        $model = ['nullable', 'integer', Rule::exists('ai_models', 'id')];
        $data = $request->validate([
            'assistant_model_id' => $model,
            'assistant_fallback_id' => [...$model, 'different:assistant_model_id'],
            'transcription_model_id' => $model,
            'transcription_fallback_id' => [...$model, 'different:transcription_model_id'],
            'timeout_seconds' => ['required', 'integer', 'min:5', 'max:120'],
            'kill_switch' => ['required', 'boolean'],
        ]);

        $settings = AiPlatformSettings::current();
        $wasOff = $settings->kill_switch;
        $settings->update($data);

        Audit::record('ai.platform_settings_updated', 'AI settings updated', subject: $settings, causer: $user, properties: $data);

        if ($wasOff !== $settings->kill_switch) {
            Audit::record($settings->kill_switch ? 'ai.platform_switched_off' : 'ai.platform_switched_on', $settings->kill_switch ? 'AI switched off for the platform' : 'AI switched on for the platform', subject: $settings, causer: $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI settings saved.')]);

        return back();
    }

    /**
     * Run the test suite against a model in the background.
     */
    public function evaluate(Request $request, AiModel $aiModel, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin($user);

        RunEvalSuite::dispatch($aiModel->id, $user->id, $request->boolean('approve'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The test suite is running. Results appear here when it finishes.')]);

        return back();
    }

    /**
     * Send a one-word prompt to a model to check it answers.
     */
    public function testModel(AiModel $aiModel, ProviderRegistry $registry, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin($user);
        $provider = $aiModel->provider;

        try {
            $reply = (new PingAgent)->prompt('Ping', provider: $registry->register($provider ?? throw new \RuntimeException('No provider')), model: $aiModel->model, timeout: 20);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('The model answered: :reply', ['reply' => Str::limit(trim($reply->text), 40)])]);
        } catch (Throwable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The model did not answer: :reason', ['reason' => Str::limit($exception->getMessage(), 200)])]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateProvider(Request $request, bool $creating): array
    {
        $driver = AiDriver::tryFrom((string) $request->input('driver'));

        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'driver' => ['required', Rule::enum(AiDriver::class)],
            'key' => [$creating ? 'required' : 'nullable', 'string', 'max:500'],
            'url' => [$driver?->needsUrl() === true ? 'required' : 'nullable', 'nullable', 'url:https', 'max:255'],
            'active' => ['required', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateModel(Request $request, ?AiModel $existing = null): array
    {
        $data = $request->validate([
            'ai_provider_id' => ['required', 'integer', Rule::exists('ai_providers', 'id')],
            'model' => ['required', 'string', 'max:150', Rule::unique('ai_models', 'model')->where('ai_provider_id', $request->input('ai_provider_id'))->ignore($existing?->id)],
            'label' => ['required', 'string', 'max:150'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => [Rule::in(['chat', 'tools', 'transcription'])],
            'status' => ['required', Rule::enum(ModelStatus::class)],
            'input_cost' => ['required', 'numeric', 'min:0', 'max:1000'],
            'output_cost' => ['required', 'numeric', 'min:0', 'max:1000'],
            'audio_cost' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $approving = $data['status'] === ModelStatus::Approved->value && $existing?->status !== ModelStatus::Approved;

        return [
            'ai_provider_id' => (int) $data['ai_provider_id'],
            'model' => $data['model'],
            'label' => $data['label'],
            'capabilities' => array_values(array_unique($data['capabilities'])),
            'status' => $data['status'],
            'input_cost_per_mtok' => (int) round((float) $data['input_cost'] * 1_000_000),
            'output_cost_per_mtok' => (int) round((float) $data['output_cost'] * 1_000_000),
            'audio_cost_per_minute' => (int) round((float) $data['audio_cost'] * 1_000_000),
            ...($approving ? ['certified_at' => now()] : []),
        ];
    }

    private function authorizeSuperAdmin(User $user): void
    {
        abort_unless($user->isSuperAdmin(), 403);
    }
}
