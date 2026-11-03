<?php

declare(strict_types=1);

namespace App\Domains\Ai;

/**
 * AI providers the platform can use (laravel/ai drivers).
 */
enum AiDriver: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case Gemini = 'gemini';
    case OpenAiCompatible = 'openai-compatible';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic',
            self::OpenAi => 'OpenAI',
            self::Gemini => 'Google Gemini',
            self::OpenAiCompatible => __('OpenAI-compatible service'),
        };
    }

    public function needsUrl(): bool
    {
        return $this === self::OpenAiCompatible;
    }
}
