<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\Assistant\Knowledge\KnowledgePack;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class GetHotelInfo extends AssistantTool
{
    public function name(): string
    {
        return 'get_hotel_info';
    }

    public function description(): string
    {
        return 'The hotel\'s full published information (policies, directions, payments, restaurant, house rules, questions and answers).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(array $arguments): array
    {
        return ['information' => app(KnowledgePack::class)->compile($this->context->locale)];
    }
}
