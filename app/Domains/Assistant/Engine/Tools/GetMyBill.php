<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\Conversations\Models\Conversation;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Folio\Folio;
use App\Domains\Orders\Stays;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The room bill, only for a number that belongs to a stay. Any other
 * number learns nothing about any stay.
 */
final class GetMyBill extends AssistantTool
{
    public function name(): string
    {
        return 'get_my_bill';
    }

    public function description(): string
    {
        return 'The guest\'s room bill (lines and balance), only when this WhatsApp number belongs to a guest staying at the hotel.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(array $arguments): array
    {
        $conversation = $this->context->isPractice() ? null : Conversation::query()->with('contact')->find($this->context->conversationId);
        $stay = $conversation === null ? null : app(Stays::class)->forPhone('+'.$conversation->contact?->wa_id);

        if ($stay === null) {
            return ['found' => false, 'message' => 'No stay is linked to this WhatsApp number. Do not give any bill details; reception can help.'];
        }

        $hotel = app(TenantContext::class)->hotel();
        $statement = app(Folio::class)->statement($stay);
        $format = function (int $minor) use ($hotel): string {
            $money = Money::ofMinor($minor, $hotel->currency);
            $this->context->allowMoney($money->abs());

            return MoneyFormatter::format($money, $this->context->locale);
        };

        return [
            'found' => true,
            'booking' => $stay->reference,
            'lines' => array_map(fn (array $l): array => ['description' => $l['description'], 'amount' => $format($l['amount_minor']), 'type' => $l['type']], array_values(array_filter($statement['lines'], fn (array $l): bool => ! $l['corrected'] && ! $l['is_correction']))),
            'charges' => $format($statement['charges_minor']),
            'payments' => $format($statement['payments_minor']),
            'balance_due' => $format($statement['balance_minor']),
        ];
    }
}
