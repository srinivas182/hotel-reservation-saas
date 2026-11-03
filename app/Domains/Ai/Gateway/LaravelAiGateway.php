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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
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
        $this->controls->assertAllowed();

        $settings = AiPlatformSettings::current();
        $models = $settings->modelsFor($task);

        if ($models === []) {
            throw new AiUnavailable('no_model', __('No AI model is set up for this task.'));
        }

        $failed = [];

        foreach ($models as $model) {
            $started = hrtime(true);

            try {
                $response = $agent->prompt(
                    $prompt,
                    provider: $this->registry->register($model->provider ?? throw new AiUnavailable('no_model')),
                    model: $model->model,
                    timeout: $settings->timeout_seconds,
                );
            } catch (Throwable $exception) {
                $this->record($task, $model, $conversationId, $agent, $prompt, null, 0, 0, $started, 'error', $exception->getMessage());
                Log::warning('AI model failed', ['task' => $task->value, 'model' => $model->model, 'error' => Str::limit($exception->getMessage(), 300)]);
                $failed[] = $model->model;

                continue;
            }

            $run = $this->record(
                $task, $model, $conversationId, $agent, $prompt, $response->text,
                $response->usage->inputTokens, $response->usage->outputTokens, $started, 'ok', null,
            );

            $this->ledger->record("ai.{$task->value}", 1, 'ai_run', (string) $run->id);
            $this->ledger->record('ai.cost_micro_usd', $run->cost_micro_usd, 'ai_run', (string) $run->id);
            $this->controls->alertIfNearLimit();

            return new AiResult($response, $model, $run, $failed);
        }

        throw new AiUnavailable('all_failed', __('The AI providers did not answer.'));
    }

    private function record(
        AiTask $task,
        AiModel $model,
        ?string $conversationId,
        Agent $agent,
        string $prompt,
        ?string $reply,
        int $inputTokens,
        int $outputTokens,
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
            'cost_micro_usd' => $model->cost($inputTokens, $outputTokens),
            'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'outcome' => $outcome,
            'error' => $error === null ? null : Str::limit($error, 490),
            'request' => json_encode(['agent' => $agent::class, 'instructions' => (string) $agent->instructions(), 'prompt' => $prompt], JSON_UNESCAPED_UNICODE),
            'response' => $reply,
        ]);
    }
}
