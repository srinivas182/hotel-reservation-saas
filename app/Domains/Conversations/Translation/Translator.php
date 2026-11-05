<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Translation;

use App\Domains\Ai\AiTask;
use App\Domains\Ai\Exceptions\AiUnavailable;
use App\Domains\Ai\Gateway\AiGateway;
use App\Domains\Assistant\Engine\Guardrails;
use App\Domains\Conversations\Models\Message;
use App\Domains\Hotel\Support\Locales;

/**
 * AI translation for staff: drafts of replies (always checked by staff
 * before sending) and guest messages shown in the staff member's language.
 */
final class Translator
{
    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @throws AiUnavailable
     */
    public function translate(string $text, string $language, ?string $conversationId = null): string
    {
        $text = trim($text);

        if ($text === '' || ! array_key_exists($language, Locales::SUPPORTED)) {
            return $text;
        }

        $result = $this->gateway->prompt(AiTask::Translation, new TranslationAgent($language), Guardrails::redact($text), $conversationId);

        return trim($result->response->text);
    }

    /**
     * Translate a guest message and keep the translation with it.
     *
     * @throws AiUnavailable
     */
    public function translateMessage(Message $message, string $language): string
    {
        if ($message->translation_language === $language && $message->translation !== null) {
            return $message->translation;
        }

        $source = $message->type === 'audio' ? (string) $message->transcript : (string) $message->body;
        $translation = $this->translate($source, $language, $message->conversation_id);

        $message->forceFill(['translation' => $translation, 'translation_language' => $language])->save();

        return $translation;
    }
}
