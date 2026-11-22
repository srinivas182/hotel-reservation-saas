<?php

declare(strict_types=1);

namespace App\Domains\Ai;

use App\Domains\Ai\Models\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The models a provider's key can use, read from the provider itself, so
 * the platform team picks a model instead of typing its id. Capabilities
 * are a first guess (speech models transcribe, the others chat with
 * tools); the test suite decides whether a model is approved.
 */
final class ModelCatalog
{
    /** OpenAI ids that are not for chat or transcription. */
    private const OPENAI_SKIP = ['embedding', 'tts', 'dall-e', 'moderation', 'image', 'realtime', 'audio-preview', 'search', 'davinci', 'babbage', 'computer-use', 'sora', 'codex'];

    /**
     * @return list<array{id: string, label: string, capabilities: list<string>}>
     */
    public function for(AiProvider $provider): array
    {
        $key = (string) ($provider->credentials['key'] ?? '');

        if ($key === '') {
            throw new RuntimeException(__('This provider has no key: edit it and enter the key first.'));
        }

        try {
            $models = match ($provider->driver) {
                AiDriver::OpenAi => $this->openAi('https://api.openai.com/v1', $key, true),
                AiDriver::OpenAiCompatible => $this->openAi(rtrim((string) ($provider->credentials['url'] ?? ''), '/'), $key, false),
                AiDriver::Anthropic => $this->anthropic($key),
                AiDriver::Gemini => $this->gemini($key),
            };
        } catch (ConnectionException $e) {
            throw new RuntimeException(__('The provider could not be reached: :error', ['error' => $e->getMessage()]), previous: $e);
        }

        usort($models, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return array_map(fn (array $m): array => [
            'id' => $m['id'],
            'label' => $m['label'],
            'capabilities' => self::guessCapabilities($m['id']),
        ], $models);
    }

    /**
     * @return list<string>
     */
    public static function guessCapabilities(string $id): array
    {
        $id = strtolower($id);

        return str_contains($id, 'whisper') || str_contains($id, 'transcribe') ? ['transcription'] : ['chat', 'tools'];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function openAi(string $base, string $key, bool $filter): array
    {
        if ($base === '') {
            throw new RuntimeException(__('This provider has no address: edit it and enter the URL first.'));
        }

        $data = $this->json(Http::withToken($key)->acceptJson()->timeout(15)->get($base.'/models'));
        $models = [];

        foreach ((array) ($data['data'] ?? []) as $model) {
            $id = is_array($model) && is_string($model['id'] ?? null) ? $model['id'] : null;

            if ($id === null || ($filter && ! $this->usableOpenAi($id))) {
                continue;
            }

            $models[] = ['id' => $id, 'label' => $id];
        }

        return $models;
    }

    private function usableOpenAi(string $id): bool
    {
        foreach (self::OPENAI_SKIP as $skip) {
            if (str_contains($id, $skip)) {
                return false;
            }
        }

        return str_starts_with($id, 'gpt-') || str_starts_with($id, 'chatgpt') || preg_match('/^o\d/', $id) === 1
            || str_contains($id, 'whisper') || str_contains($id, 'transcribe');
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function anthropic(string $key): array
    {
        $data = $this->json(Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
            ->acceptJson()->timeout(15)->get('https://api.anthropic.com/v1/models', ['limit' => 100]));

        $models = [];
        foreach ((array) ($data['data'] ?? []) as $model) {
            if (is_array($model) && is_string($model['id'] ?? null)) {
                $models[] = ['id' => $model['id'], 'label' => is_string($model['display_name'] ?? null) ? $model['display_name'] : $model['id']];
            }
        }

        return $models;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function gemini(string $key): array
    {
        $data = $this->json(Http::withHeaders(['x-goog-api-key' => $key])->acceptJson()->timeout(15)
            ->get('https://generativelanguage.googleapis.com/v1beta/models', ['pageSize' => 200]));

        $models = [];
        foreach ((array) ($data['models'] ?? []) as $model) {
            $methods = is_array($model) ? (array) ($model['supportedGenerationMethods'] ?? []) : [];

            if (! is_array($model) || ! is_string($model['name'] ?? null) || ! in_array('generateContent', $methods, true)) {
                continue;
            }

            $id = str_replace('models/', '', $model['name']);
            $models[] = ['id' => $id, 'label' => is_string($model['displayName'] ?? null) ? $model['displayName'] : $id];
        }

        return $models;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        if (! $response->successful()) {
            $message = $response->json('error.message') ?? $response->json('error') ?? 'HTTP '.$response->status();

            throw new RuntimeException(__('The provider refused: :reason', ['reason' => is_string($message) ? $message : 'HTTP '.$response->status()]));
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }
}
