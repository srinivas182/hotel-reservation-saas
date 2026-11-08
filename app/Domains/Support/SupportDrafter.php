<?php

declare(strict_types=1);

namespace App\Domains\Support;

use App\Domains\Ai\AiTask;
use App\Domains\Ai\Gateway\ProviderRegistry;
use App\Domains\Ai\Models\AiPlatformSettings;
use App\Domains\Assistant\Engine\Guardrails;
use App\Domains\Audit\Audit;
use App\Domains\Help\HelpSearch;
use App\Domains\Help\Models\HelpArticle;
use App\Domains\Platform\HotelHealth;
use App\Domains\Support\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * An AI draft for a support reply, from the help articles and the hotel's
 * own state. Paid by the platform (not the hotel's credits); never sent
 * without a person.
 */
final class SupportDrafter
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    public function draft(SupportTicket $ticket, User $agent): string
    {
        $ticket->loadMissing(['hotel', 'requester', 'messages']);
        $language = $ticket->requester?->preferred_locale === 'en' ? 'en' : 'fr';
        $thread = $ticket->messages->where('internal', false)->map(fn ($m): string => ($m->side === 'hotel' ? 'Hotel: ' : 'Support: ').Guardrails::redact($m->body))->implode("\n\n");

        $articles = HelpSearch::find($ticket->subject.' '.(string) $ticket->messages->first()?->body, $language, 'support', 3)
            ->map(fn (HelpArticle $a): string => '## '.$a->text('title', $language)."\n".$a->text('body', $language))->implode("\n\n");

        $state = $ticket->hotel === null ? [] : app(HotelHealth::class)->for($ticket->hotel);
        $facts = $state === [] ? '' : json_encode([
            'hotel' => $ticket->hotel->name, 'status' => $state['status'], 'assistant_mode' => $state['mode'], 'plan' => $state['plan'],
            'whatsapp' => $state['whatsapp']['status'] ?? 'not connected', 'setup_done' => $state['setup'], 'issues' => $state['issues'],
            'ai_credits_left' => isset($state['credits']['ai']['left']) ? intdiv((int) $state['credits']['ai']['left'], 100) : null,
        ], JSON_UNESCAPED_UNICODE);

        $prompt = 'Person: '.(string) $ticket->requester?->first_name."\nSubject: {$ticket->subject}\n\nConversation:\n{$thread}\n\nHotel facts:\n{$facts}\n\nHelp articles:\n{$articles}";
        $settings = AiPlatformSettings::current();

        foreach ($settings->modelsFor(AiTask::Assistant) as $model) {
            try {
                $response = (new SupportDraftAgent($language))->prompt($prompt, provider: $this->registry->register($model->provider ?? throw new RuntimeException('no provider')), model: $model->model, timeout: $settings->timeout_seconds);
                Log::info('Support AI draft', ['ticket' => $ticket->id, 'model' => $model->model, 'input' => $response->usage->inputTokens, 'output' => $response->usage->outputTokens]);
                Audit::record('support.ai_draft', 'AI draft prepared for a support reply', subject: $ticket->hotel, causer: $agent, properties: ['ticket' => $ticket->id, 'model' => $model->model]);

                return trim($response->text);
            } catch (Throwable $e) {
                Log::warning('Support AI draft failed', ['ticket' => $ticket->id, 'model' => $model->model, 'error' => $e->getMessage()]);
            }
        }

        throw new RuntimeException((string) __('No AI draft right now. Please write the reply.'));
    }
}
