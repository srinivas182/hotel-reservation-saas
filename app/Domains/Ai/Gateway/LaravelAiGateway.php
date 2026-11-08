<?php

declare(strict_types=1);

namespace App\Domains\Ai\Gateway;

use App\Domains\Ai\AiControls;
use App\Domains\Ai\AiTask;
use App\Domains\Ai\Exceptions\AiUnavailable;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiPlatformSettings;
use App\Domains\Ai\Models\AiRun;
use App\Domains\Messaging\Usage\UsageLedger;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Transcription;
use Throwable;

/**
 * AiGateway on top of laravel/ai. Failover is done here (not by the
 * library) so that any failure of the main model, including timeouts and
 * server errors, moves to the backup, and every attempt is recorded.
 */
final class LaravelAiGateway implements AiGateway
{
    public function __construct(
        private readonly AiControls $controls,
        private readonly ProviderRegistry $registry,
        private readonly UsageLedger $ledger,
    ) {}

    public function prompt(AiTask $task, Agent $agent, string $prompt, ?string $conversationId = null): AiResult
    {
        $request = json_encode(['agent' => $agent::class, 'instructions' => (string) $agent->instructions(), 'prompt' => $prompt], JSON_UNESCAPED_UNICODE) ?: '';

        /** @var array{0: AgentResponse, 1: AiModel, 2: AiRun, 3: list<string>} $result */
        $result = $this->attempt($task, $conversationId, $request, function (AiModel $model, string $provider, int $timeout) use ($agent, $prompt): array {
            $response = $agent->prompt($prompt, provider: $provider, model: $model->model, timeout: $timeout);

            return [$response, $response->text, $response->usage->inputTokens, $response->usage->outputTokens, 0];
        });

        return new AiResult($result[0], $result[1], $result[2], $result[3]);
    }

    public function transcribe(string $path, ?string $conversationId = null): TranscriptResult
    {
        /** @var array{0: \Laravel\Ai\Responses\TranscriptionResponse, 1: AiModel, 2: AiRun, 3: list<string>} $result */
        $result = $this->attempt(AiTask::Transcription, $conversationId, json_encode(['audio' => $path]) ?: '', function (AiModel $model, string $provider, int $timeout) use ($path): array {
            $response = Transcription::fromStorage($path, 'local')->timeout(max($timeout, 30))->generate($provider, $model->model);

            return [
                $response,
                $response->text,
                $response->usage->inputTokens,
                $response->usage->outputTokens,
                (int) ceil((float) ($response->usage->audioSeconds ?? 0)),
            ];
        });

        return new TranscriptResult(trim($result[0]->text), $result[1], $result[2], $result[3]);
    }

    /**
     * Run one call against the task's models in order.
     *
     * @param  Closure(AiModel, string, int): array{0: mixed, 1: string, 2: int, 3: int, 4: int}  $call
     * @return array{0: mixed, 1: AiModel, 2: AiRun, 3: list<string>}
     */
    private function attempt(AiTask $task, ?string $conversationId, string $request, Closure $call): array
    {
        $this->controls->assertAllowed();

        $settings = AiPlatformSettings::current();
        $models = $settings->modelsFor($task);

        if ($models === []) {
            throw new AiUnavailable('no_model', __('No AI model is set up for this task.'));
        }

        $failed = [];
        $breaker = app(CircuitBreaker::class);

        // Models that keep failing are skipped for a minute, unless every model is skipped.
        $healthy = array_values(array_filter($models, fn (AiModel $m): bool => ! $breaker->isOpen((int) $m->id)));
        $models = $healthy === [] ? $models : $healthy;

        foreach ($models as $model) {
            $started = hrtime(true);

            try {
                [$response, $text, $input, $output, $audio] = $call(
                    $model,
                    $this->registry->register($model->provider ?? throw new AiUnavailable('no_model')),
                    $settings->timeout_seconds,
                );
            } catch (Throwable $exception) {
                $this->record($task, $model, $conversationId, $request, null, 0, 0, 0, $started, 'error', $exception->getMessage());
                Log::warning('AI model failed', ['task' => $task->value, 'model' => $model->model, 'error' => Str::limit($exception->getMessage(), 300)]);
                $failed[] = $model->model;
                $breaker->failure((int) $model->id);

                continue;
            }

            $run = $this->record($task, $model, $conversationId, $request, $text, $input, $output, $audio, $started, 'ok', null);
            $breaker->success((int) $model->id);

            $this->ledger->record("ai.{$task->value}", 1, 'ai_run', (string) $run->id);
            $this->ledger->record('ai.cost_micro_usd', $run->cost_micro_usd, 'ai_run', (string) $run->id);
            $this->controls->alertIfNearLimit();

            return [$response, $model, $run, $failed];
        }

        throw new AiUnavailable('all_failed', __('The AI providers did not answer.'));
    }

    private function record(
        AiTask $task,
        AiModel $model,
        ?string $conversationId,
        string $request,
        ?string $reply,
        int $inputTokens,
        int $outputTokens,
        int $audioSeconds,
        int $started,
        string $outcome,
        ?string $error,
    ): AiRun {
        return AiRun::query()->create([
            'conversation_id' => $conversationId,
            'task' => $task,
            'ai_model_id' => $model->id,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'audio_seconds' => $audioSeconds,
            'cost_micro_usd' => $model->cost($inputTokens, $outputTokens, $audioSeconds),
            'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'outcome' => $outcome,
            'error' => $error === null ? null : Str::limit($error, 490),
            'request' => $request,
            'response' => $reply,
        ]);
    }
}
