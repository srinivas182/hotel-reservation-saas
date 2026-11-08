<?php

declare(strict_types=1);

namespace App\Domains\Onboarding;

use App\Domains\Ai\AiTask;
use App\Domains\Ai\Gateway\AiGateway;
use App\Domains\Assistant\Engine\Guardrails;
use App\Domains\Assistant\Knowledge\KnowledgeEntry;
use App\Domains\Assistant\Knowledge\KnowledgeSection;
use App\Domains\Audit\Audit;
use App\Domains\Onboarding\Models\OnboardingProgress;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Drafts the information pack (French and English) from the owner's
 * answers. The draft goes into the editable entries; nothing is published
 * until the owner publishes it.
 */
final class PackDrafter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AiGateway $gateway,
    ) {}

    /**
     * @param  array<string, string>  $answers
     * @return int entries written
     */
    public function draft(array $answers, User $by): int
    {
        $answers = array_filter(array_intersect_key($answers, Questionnaire::questions()), fn (string $a): bool => trim($a) !== '');

        if ($answers === []) {
            throw new RuntimeException((string) __('Answer at least one question.'));
        }

        OnboardingProgress::current()->forceFill(['questionnaire' => $answers])->save();

        $hotel = $this->context->hotel();
        $lines = [];
        foreach ($answers as $key => $answer) {
            $lines[] = '['.Questionnaire::sections()[$key].'] '.Questionnaire::questions()[$key]."\n".Guardrails::redact(trim($answer));
        }

        $result = $this->gateway->prompt(AiTask::Translation, new PackDraftAgent($hotel->name), implode("\n\n", $lines));
        $entries = $this->parse($result->response->text);

        $written = DB::transaction(function () use ($entries): int {
            $sections = array_values(array_unique(array_column($entries, 'section')));
            KnowledgeEntry::query()->whereIn('section', $sections)->delete();

            foreach ($entries as $i => $entry) {
                KnowledgeEntry::query()->create([...$entry, 'sort_order' => $i]);
            }

            return count($entries);
        });

        Audit::record('onboarding.pack_drafted', 'Information pack drafted from the questionnaire', subject: $hotel, causer: $by, properties: ['answers' => count($answers), 'entries' => $written]);

        return $written;
    }

    /**
     * @return list<array{locale: string, section: string, question: string|null, content: string}>
     */
    private function parse(string $text): array
    {
        $json = json_decode((string) preg_replace('/^```(json)?|```$/m', '', trim($text)), true);

        if (! is_array($json) || ! isset($json['entries']) || ! is_array($json['entries'])) {
            throw new RuntimeException((string) __('The draft could not be prepared. Please try again.'));
        }

        $entries = [];
        foreach ($json['entries'] as $entry) {
            $section = KnowledgeSection::tryFrom((string) ($entry['section'] ?? ''));
            $locale = (string) ($entry['locale'] ?? '');
            $content = trim((string) ($entry['content'] ?? ''));

            if ($section === null || ! in_array($locale, ['fr', 'en'], true) || $content === '') {
                continue;
            }

            $entries[] = ['locale' => $locale, 'section' => $section->value, 'question' => isset($entry['question']) ? mb_substr(trim((string) $entry['question']), 0, 255) : null, 'content' => mb_substr($content, 0, 4000)];
        }

        if ($entries === []) {
            throw new RuntimeException((string) __('The draft could not be prepared. Please try again.'));
        }

        return $entries;
    }
}
