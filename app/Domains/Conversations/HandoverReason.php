<?php

declare(strict_types=1);

namespace App\Domains\Conversations;

/**
 * Plain-language wording for why a conversation was passed to staff.
 * Codes come from the assistant engine and background jobs; free text
 * written by the assistant itself is shown as it is.
 */
final class HandoverReason
{
    public static function label(?string $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        [$code, $detail] = array_pad(explode(':', $reason, 2), 2, '');

        return match ($code) {
            'ai_unavailable' => match ($detail) {
                'no_credits' => __('the hotel’s AI credits are used up'),
                'reduced_mode' => __('an invoice is unpaid, so the assistant is paused'),
                'suspended' => __('the hotel is suspended'),
                'hotel_off', 'platform_off' => __('the assistant is switched off'),
                'budget' => __('the AI budget for today is reached'),
                'no_model' => __('no AI model is set up yet'),
                default => __('the assistant could not answer (AI service unavailable)'),
            },
            'guardrail' => __('the reply did not pass the safety checks'),
            'no_information_pack' => __('the information pack is not published yet'),
            'nothing_to_answer' => __('the assistant had nothing to answer'),
            'request_expired' => __('a booking request expired without an answer'),
            'table_request_expired' => __('a table request expired without an answer'),
            'order_guest_not_told' => __('the guest could not be told about their order'),
            'event_client_not_told' => __('the client could not be told about their quote'),
            default => $reason,
        };
    }
}
