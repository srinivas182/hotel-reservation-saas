<?php

declare(strict_types=1);

namespace App\Domains\Ai;

enum AiTask: string
{
    case Assistant = 'assistant';
    case Transcription = 'transcription';
    case Translation = 'translation';

    public function label(): string
    {
        return match ($this) {
            self::Assistant => __('Assistant replies'),
            self::Transcription => __('Voice note transcription'),
            self::Translation => __('Translation of messages'),
        };
    }
}
