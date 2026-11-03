<?php

declare(strict_types=1);

use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiPlatformSettings;
use App\Domains\Ai\Models\AiProvider;

/**
 * Two providers with an assistant model each: main (Anthropic) and backup (OpenAI).
 *
 * @return array{main: AiModel, backup: AiModel}
 */
function setUpAiModels(): array
{
    $anthropic = AiProvider::query()->create(['name' => 'Anthropic (MCS)', 'driver' => 'anthropic', 'credentials' => ['key' => 'sk-ant-test-1234'], 'active' => true]);
    $openai = AiProvider::query()->create(['name' => 'OpenAI (MCS)', 'driver' => 'openai', 'credentials' => ['key' => 'sk-openai-test-5678'], 'active' => true]);

    $main = AiModel::query()->create([
        'ai_provider_id' => $anthropic->id, 'model' => 'main-model', 'label' => 'Main', 'capabilities' => ['chat', 'tools'],
        'status' => 'approved', 'input_cost_per_mtok' => 1_000_000, 'output_cost_per_mtok' => 5_000_000,
    ]);
    $backup = AiModel::query()->create([
        'ai_provider_id' => $openai->id, 'model' => 'backup-model', 'label' => 'Backup', 'capabilities' => ['chat', 'tools'],
        'status' => 'approved', 'input_cost_per_mtok' => 400_000, 'output_cost_per_mtok' => 1_600_000,
    ]);

    AiPlatformSettings::current()->update(['assistant_model_id' => $main->id, 'assistant_fallback_id' => $backup->id]);

    return ['main' => $main, 'backup' => $backup];
}
