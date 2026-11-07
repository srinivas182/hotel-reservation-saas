<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine;

use App\Domains\Assistant\Engine\Tools\BookTable;
use App\Domains\Assistant\Engine\Tools\CheckAvailability;
use App\Domains\Assistant\Engine\Tools\EventEnquiry;
use App\Domains\Assistant\Engine\Tools\GetHotelInfo;
use App\Domains\Assistant\Engine\Tools\GetMenu;
use App\Domains\Assistant\Engine\Tools\GetMyBill;
use App\Domains\Assistant\Engine\Tools\GetQuote;
use App\Domains\Assistant\Engine\Tools\ListRoomCategories;
use App\Domains\Assistant\Engine\Tools\PlaceOrder;
use App\Domains\Assistant\Engine\Tools\RequestBooking;
use App\Domains\Assistant\Engine\Tools\RestaurantAvailability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;

/**
 * The hotel's WhatsApp assistant. Read-only tools only (S5): it can check
 * availability and prices but cannot reserve anything.
 */
final class HotelAssistantAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;

    public const INTENTS = [
        'greeting', 'availability_question', 'price_question', 'booking_request', 'information_question',
        'modification_or_cancellation', 'complaint', 'human_request', 'group_or_special_request', 'table_booking', 'menu_question', 'food_order', 'bill_question', 'event_enquiry', 'other',
    ];

    /**
     * @param  list<AssistantTurn>  $history  earlier turns, oldest first (the new guest message is the prompt)
     */
    public function __construct(
        private readonly string $instructions,
        private readonly array $history,
        private readonly ToolContext $context,
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * @return iterable<Message>
     */
    public function messages(): iterable
    {
        return array_map(
            fn (AssistantTurn $turn): Message => $turn->role === 'guest' ? new UserMessage($turn->text) : new AssistantMessage($turn->text),
            $this->history,
        );
    }

    public function tools(): iterable
    {
        return [
            new ListRoomCategories($this->context),
            new CheckAvailability($this->context),
            new GetQuote($this->context),
            new GetHotelInfo($this->context),
            new RequestBooking($this->context),
            new RestaurantAvailability($this->context),
            new BookTable($this->context),
            new GetMenu($this->context),
            new PlaceOrder($this->context),
            new GetMyBill($this->context),
            new EventEnquiry($this->context),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reply' => $schema->string()->description('The message to send to the guest, in the guest\'s language')->required(),
            'language' => $schema->string()->description('ISO 639-1 code of the guest\'s language, e.g. fr or en')->required(),
            'intent' => $schema->string()->enum(self::INTENTS)->required(),
            'handover' => $schema->boolean()->description('True when hotel staff must take over')->required(),
            'handover_reason' => $schema->string()->description('Why staff must take over (empty when handover is false)')->required(),
            'confidence' => $schema->number()->description('0 to 1: how sure you are the reply is correct and complete')->required(),
        ];
    }
}
