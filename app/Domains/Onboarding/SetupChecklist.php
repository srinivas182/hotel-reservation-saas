<?php

declare(strict_types=1);

namespace App\Domains\Onboarding;

use App\Domains\Access\Models\HotelInvitation;
use App\Domains\Ai\AiTask;
use App\Domains\Ai\Models\AiRun;
use App\Domains\Assistant\Knowledge\KnowledgeVersion;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Onboarding\Models\OnboardingProgress;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;

/**
 * The hotel's setup steps, each worked out from the hotel's real data
 * (a step is done when the thing exists), plus skipped steps.
 */
final class SetupChecklist
{
    /** Steps that can be skipped (the others are needed to go live). */
    private const OPTIONAL = ['team', 'details'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return list<array{key: string, title: string, description: string, done: bool, skipped: bool, optional: bool, url: string}>
     */
    public function steps(): array
    {
        $progress = OnboardingProgress::current();
        $settings = HotelSettings::current();
        $hotel = $this->context->hotel();
        $categories = RoomCategory::query()->where('active', true)->pluck('id');
        $withRooms = Room::query()->whereIn('room_category_id', $categories)->distinct()->pluck('room_category_id');
        $withRates = CategoryRate::query()->whereIn('room_category_id', $categories)->pluck('room_category_id')->unique();

        $done = [
            'details' => trim((string) $settings->address_line1) !== '' && trim((string) $settings->phone_e164) !== '',
            'rooms' => $categories->isNotEmpty() && $categories->diff($withRooms)->isEmpty(),
            'prices' => $categories->isNotEmpty() && $categories->diff($withRates)->isEmpty(),
            'taxes' => $progress->flagged('taxes', 'confirmed_at'),
            'hours' => array_filter((array) ($settings->staffed_hours ?? [])) !== [],
            'team' => $hotel->members()->count() > 1 || HotelInvitation::query()->exists(),
            'whatsapp' => WhatsAppAccount::query()->where('status', AccountStatus::Connected->value)->exists(),
            'knowledge' => KnowledgeVersion::query()->whereNotNull('published_at')->exists(),
            'practice' => AiRun::query()->where('task', AiTask::Assistant->value)->whereNull('conversation_id')->exists(),
        ];

        $meta = [
            'details' => [__('Hotel details'), __('Address, phone, email and check-in times guests will see.'), route('hotel.settings.edit')],
            'rooms' => [__('Rooms'), __('Your room categories and the rooms in each.'), route('hotel.setup')],
            'prices' => [__('Prices and breakfast'), __('A nightly price for each category, and breakfast if you offer it.'), route('hotel.rates')],
            'taxes' => [__('Taxes'), __('Check the taxes set for your country and confirm them.'), route('hotel.taxes.index')],
            'hours' => [__('Reception hours'), __('When someone answers at reception, so the assistant knows when to pass guests on.'), route('hotel.settings.edit')],
            'team' => [__('Invite your team'), __('Give your receptionists their own access.'), route('staff.index')],
            'whatsapp' => [__('Connect WhatsApp'), __('Connect the hotel\'s WhatsApp Business number.'), route('hotel.whatsapp')],
            'knowledge' => [__('Information pack'), __('What the assistant may tell guests: policies, breakfast, parking, directions…'), route('hotel.assistant.knowledge')],
            'practice' => [__('Try the assistant'), __('Ask it a few questions as if you were a guest.'), route('hotel.assistant.practice')],
        ];

        $steps = [];
        foreach ($meta as $key => [$title, $description, $url]) {
            $steps[] = [
                'key' => $key,
                'title' => (string) $title,
                'description' => (string) $description,
                'done' => $done[$key],
                'skipped' => ! $done[$key] && $progress->flagged($key, 'skipped_at'),
                'optional' => in_array($key, self::OPTIONAL, true),
                'url' => $url,
            ];
        }

        return $steps;
    }

    /**
     * @return array{done: int, total: int, complete: bool}
     */
    public function summary(): array
    {
        $steps = $this->steps();
        $done = count(array_filter($steps, fn (array $s): bool => $s['done'] || $s['skipped']));

        return ['done' => $done, 'total' => count($steps), 'complete' => $done === count($steps)];
    }

    public static function hasStep(string $key): bool
    {
        return in_array($key, ['details', 'rooms', 'prices', 'taxes', 'hours', 'team', 'whatsapp', 'knowledge', 'practice'], true);
    }

    public static function taxesExist(): bool
    {
        return TaxRate::query()->exists();
    }
}
