<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Onboarding\Listing\AnalyseListing;
use App\Domains\Onboarding\Listing\ListingApplier;
use App\Domains\Onboarding\Listing\ListingDraft;
use App\Domains\Onboarding\Listing\MapsLink;
use App\Domains\Onboarding\Models\ListingImport;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

/**
 * "Start from what you already have" (Sprint A): the owner gives their
 * website, a Google Maps link, some text or photos of a brochure or rate
 * card; the import is read in the background and reviewed before use.
 */
final class ListingImportController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'website_url' => ['nullable', 'url:http,https', 'max:500'],
            'maps_url' => ['nullable', 'url:http,https', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ! MapsLink::isMapsLink($value)) {
                    $fail(__('This is not a Google Maps link. In Google Maps, open your hotel, press Share and copy the link.'));
                }
            }],
            'pasted_text' => ['nullable', 'string', 'max:20000'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $files = array_values(array_filter((array) ($data['files'] ?? []), fn ($f): bool => $f instanceof UploadedFile));

        if (($data['website_url'] ?? null) === null && ($data['maps_url'] ?? null) === null && trim((string) ($data['pasted_text'] ?? '')) === '' && $files === []) {
            throw ValidationException::withMessages(['website_url' => __('Give at least one: your website, a Google Maps link, some text or a photo.')]);
        }

        if (ListingImport::query()->where('created_at', '>=', now()->subDay())->count() >= ListingImport::DAILY_LIMIT) {
            throw ValidationException::withMessages(['website_url' => __('You have read :count imports today. Please try again tomorrow, or fill in the steps by hand.', ['count' => ListingImport::DAILY_LIMIT])]);
        }

        // One import at a time: a new one replaces any draft not used yet.
        ListingImport::query()->whereIn('status', ['processing', 'ready', 'failed'])->get()->each(function (ListingImport $old): void {
            $old->forceFill(['status' => 'discarded'])->save();
            $old->deleteFiles();
        });

        $import = ListingImport::query()->create([
            'created_by' => $user->id,
            'status' => 'processing',
            'website_url' => $data['website_url'] ?? null,
            'maps_url' => $data['maps_url'] ?? null,
            'pasted_text' => ($data['pasted_text'] ?? null) === null ? null : trim((string) $data['pasted_text']),
        ]);

        $stored = [];
        foreach ($files as $file) {
            $extension = mb_strtolower($file->guessExtension() ?? $file->getClientOriginalExtension());
            $path = $file->storeAs(ListingImport::directory($this->context->id(), $import->id), Str::random(24).'.'.$extension, 'local');
            if (is_string($path)) {
                $stored[] = ['path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 120), 'mime' => (string) $file->getMimeType(), 'size' => (int) $file->getSize()];
            }
        }
        $import->forceFill(['files' => $stored === [] ? null : $stored])->save();

        Audit::record('onboarding.listing_started', 'Set-up import started', causer: $user, properties: ['import' => $import->id, 'files' => count($stored)]);
        AnalyseListing::dispatch($import->id);

        return back();
    }

    public function apply(Request $request, ListingImport $import, ListingApplier $applier, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'profile' => ['nullable', 'array'],
            'profile.*' => ['string', 'in:name,address_line1,city,check_in_time,check_out_time,location'],
            'rooms' => ['nullable', 'array', 'max:20'],
            'rooms.*.index' => ['required', 'integer', 'min:0'],
            'rooms.*.name' => ['required', 'string', 'max:60'],
            'rooms.*.count' => ['required', 'integer', 'min:1', 'max:500'],
            'rooms.*.adults' => ['required', 'integer', 'min:1', 'max:10'],
            'rooms.*.price' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'entries' => ['nullable', 'array', 'max:60'],
            'entries.*' => ['integer', 'min:0'],
        ]);

        $choice = [
            'profile' => array_values(array_map('strval', (array) ($data['profile'] ?? []))),
            'rooms' => array_values(array_map(fn (array $r): array => ['index' => (int) $r['index'], 'name' => trim((string) $r['name']), 'count' => (int) $r['count'], 'adults' => (int) $r['adults'], 'price' => isset($r['price']) ? (int) $r['price'] : null], (array) ($data['rooms'] ?? []))),
            'entries' => array_values(array_map('intval', (array) ($data['entries'] ?? []))),
        ];

        if ($choice['profile'] === [] && $choice['rooms'] === [] && $choice['entries'] === []) {
            throw ValidationException::withMessages(['listing' => __('Tick at least one item to use, or discard this import.')]);
        }

        try {
            $result = $applier->apply($import, $choice, $user);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['listing' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Done: :profile details, :rooms rooms, :prices prices and :entries information entries added. Check the information pack, then publish it.', [
            'profile' => $result['profile'], 'rooms' => $result['rooms'], 'prices' => $result['prices'], 'entries' => $result['entries'],
        ])]);

        return back();
    }

    public function discard(ListingImport $import, #[CurrentUser] User $user): RedirectResponse
    {
        if (in_array($import->status, ['processing', 'ready', 'failed'], true)) {
            $import->forceFill(['status' => 'discarded'])->save();
            $import->deleteFiles();
            Audit::record('onboarding.listing_discarded', 'Set-up import discarded', causer: $user, properties: ['import' => $import->id]);
        }

        return back();
    }

    /**
     * The import shown on the setup page: the latest one not used or
     * discarded yet, with what is already set so the owner can compare.
     *
     * @return array<string, mixed>|null
     */
    public static function present(User $user): ?array
    {
        $import = ListingImport::query()->whereIn('status', ['processing', 'ready', 'failed'])->latest()->first();

        if ($import === null) {
            return null;
        }

        $hotel = app(TenantContext::class)->hotel();
        $settings = HotelSettings::current();
        $existing = RoomCategory::query()->get()->map(fn (RoomCategory $c): string => mb_strtolower($c->localizedName($hotel->default_locale, $hotel->default_locale)))->all();
        $draft = $import->draft ?? [];

        return [
            'id' => $import->id,
            'status' => $import->status,
            'error' => $import->error,
            'notes' => $draft['notes'] ?? [],
            'sources' => array_keys(array_filter(['website' => $import->website_url, 'maps' => $import->maps_url, 'text' => $import->pasted_text, 'files' => $import->files])),
            'currency' => $hotel->currency,
            // In a fixed order: MySQL does not keep the order of JSON keys.
            'profile' => (object) array_filter(array_map(fn (string $key): ?array => $draft['profile'][$key] ?? null, array_combine(ListingDraft::PROFILE, ListingDraft::PROFILE))),
            'current' => [
                'name' => $hotel->name,
                'address_line1' => $settings->address_line1,
                'city' => $settings->city,
                'check_in_time' => substr($settings->check_in_time, 0, 5),
                'check_out_time' => substr($settings->check_out_time, 0, 5),
                'location' => $settings->latitude === null ? null : ['latitude' => $settings->latitude, 'longitude' => $settings->longitude],
            ],
            'rooms' => array_map(fn (array $r): array => [...$r, 'exists' => in_array(mb_strtolower($r['name']), $existing, true)], $draft['rooms'] ?? []),
            'entries' => $draft['entries'] ?? [],
            'can' => ['rooms' => $user->can('rooms.manage'), 'rates' => $user->can('rates.manage'), 'pack' => $user->can('assistant.manage')],
        ];
    }
}
