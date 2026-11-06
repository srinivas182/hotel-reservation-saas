<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\Restaurant\Menu\MenuCatalog;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Dishes served now (or at the next service today) with the price the guest
 * pays. Every price returned may be stated in the reply.
 */
final class GetMenu extends AssistantTool
{
    public function name(): string
    {
        return 'get_menu';
    }

    public function description(): string
    {
        return 'The menu of a restaurant or bar now: dishes available today with exact prices, labels and allergens, plus the link to the full menu page.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'outlet' => $schema->string()->description('Outlet code (empty: the first restaurant)'),
        ];
    }

    protected function run(array $arguments): array
    {
        $hotel = app(TenantContext::class)->hotel();
        $outlets = Outlet::query()->where('active', true)->orderBy('sort_order')->get();
        $outlet = $outlets->firstWhere('public_slug', (string) ($arguments['outlet'] ?? '')) ?? $outlets->first();

        if ($outlet === null) {
            return ['error' => 'The hotel has no restaurant or bar set up.'];
        }

        $menu = app(MenuCatalog::class)->forGuests($outlet, $this->context->locale);

        $sections = [];
        foreach ($menu['sections'] as $section) {
            $items = [];
            foreach (array_slice($section['items'], 0, 15) as $item) {
                $this->context->allowMoney($item['price']);
                $items[] = ['name' => $item['name'], 'price' => $item['price_text'], 'description' => $item['description'], 'labels' => $item['labels'], 'allergens' => $item['allergens']];
            }
            $sections[] = ['section' => $section['name'], 'dishes' => $items];
        }

        return [
            'outlet' => $outlet->localizedName($this->context->locale, $hotel->default_locale),
            'service' => $menu['period'],
            'sections' => $sections,
            'full_menu_link' => route('menu.public', [$hotel->slug, $outlet->public_slug]),
        ];
    }
}
