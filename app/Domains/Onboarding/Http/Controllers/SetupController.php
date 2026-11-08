<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Onboarding\Imports\BookingImport;
use App\Domains\Onboarding\Imports\CsvReader;
use App\Domains\Onboarding\Imports\RoomImport;
use App\Domains\Onboarding\Models\CountryTemplate;
use App\Domains\Onboarding\Models\OnboardingProgress;
use App\Domains\Onboarding\QuickRooms;
use App\Domains\Onboarding\SetupChecklist;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The setup assistant: checklist, quick room creation and file imports.
 */
final class SetupController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SetupChecklist $checklist,
    ) {}

    public function show(Request $request): Response
    {
        $hotel = $this->context->hotel();
        $numbers = Room::query()->pluck('number')->filter(fn ($n): bool => ctype_digit((string) $n))->map(fn ($n): int => (int) $n);

        return Inertia::render('hotel/setup', [
            'steps' => $this->checklist->steps(),
            'summary' => $this->checklist->summary(),
            'categories' => RoomCategory::query()->withCount('rooms')->orderBy('sort_order')->get()->map(fn (RoomCategory $c): array => ['id' => $c->id, 'code' => $c->code, 'name' => $c->localizedName($hotel->default_locale, $hotel->default_locale), 'rooms' => $c->rooms_count])->values(),
            'suggestions' => CountryTemplate::query()->where('country_code', $hotel->country_code)->value('category_suggestions') ?? ['Standard', 'Supérieure', 'Suite'],
            'firstNumber' => $numbers->isEmpty() ? 101 : $numbers->max() + 1,
            'columns' => ['rooms' => RoomImport::COLUMNS, 'bookings' => BookingImport::COLUMNS],
            'can' => ['rooms' => $request->user()?->can('rooms.manage') === true, 'bookings' => $request->user()?->can('bookings.create') === true],
            'questionnaire' => [
                'questions' => \App\Domains\Onboarding\Questionnaire::questions(),
                'answers' => (object) (OnboardingProgress::current()->questionnaire ?? []),
            ],
            'golive' => (function () use ($request): array {
                $progress = OnboardingProgress::current();
                $user = $request->user();

                return [
                    'requested_at' => $progress->golive_requested_at?->toDateTimeString(),
                    'decision' => $progress->golive_decision,
                    'reason' => $progress->golive_reason,
                    'blockers' => $user instanceof User ? app(\App\Domains\Onboarding\GoLive::class)->blockers($user) : [],
                    'approval_required' => \App\Domains\Onboarding\Models\SignupSettings::current()->approval_required,
                ];
            })(),
        ]);
    }

    public function skip(Request $request, string $step, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless(SetupChecklist::hasStep($step), 404);
        $data = $request->validate(['skip' => ['required', 'boolean']]);
        OnboardingProgress::current()->mark($step, 'skipped_at', (bool) $data['skip']);
        Audit::record('setup.step_skipped', 'Setup step skipped or resumed', causer: $user, properties: ['step' => $step, 'skip' => (bool) $data['skip']]);

        return back();
    }

    public function confirmTaxes(#[CurrentUser] User $user): RedirectResponse
    {
        OnboardingProgress::current()->mark('taxes', 'confirmed_at');
        Audit::record('setup.taxes_confirmed', 'Taxes confirmed in the setup assistant', causer: $user);

        return back();
    }

    public function quickRooms(Request $request, QuickRooms $rooms, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'categories' => ['required', 'array', 'min:1', 'max:20'],
            'categories.*.name' => ['required', 'string', 'max:60'],
            'categories.*.count' => ['required', 'integer', 'min:1', 'max:500'],
            'categories.*.adults' => ['required', 'integer', 'min:1', 'max:10'],
            'first_number' => ['required', 'integer', 'min:1', 'max:99999'],
        ]);

        if (array_sum(array_column($data['categories'], 'count')) > 1000) {
            throw ValidationException::withMessages(['categories' => __('At most 1,000 rooms at a time.')]);
        }

        $result = $rooms->create(array_values(array_map(fn (array $c): array => ['name' => (string) $c['name'], 'count' => (int) $c['count'], 'adults' => (int) $c['adults']], $data['categories'])), (int) $data['first_number'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':rooms rooms created in :categories categories.', ['rooms' => $result['rooms'], 'categories' => $result['categories']])]);

        return back();
    }

    public function template(string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, ['rooms', 'bookings'], true), 404);
        $columns = $kind === 'rooms' ? RoomImport::COLUMNS : BookingImport::COLUMNS;
        $example = $kind === 'rooms' ? ['STD', '101', '1'] : ['Awa', 'Koné', '+2250700000001', 'awa@example.com', now()->addDays(10)->toDateString(), now()->addDays(12)->toDateString(), 'STD', '1', '2', '40000', 'ANC-123'];

        return response()->streamDownload(function () use ($columns, $example): void {
            $out = fopen('php://output', 'w');
            if ($out !== false) {
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $columns, ';', '"', '');
                fputcsv($out, $example, ';', '"', '');
                fclose($out);
            }
        }, "import-{$kind}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request, string $kind, RoomImport $rooms, BookingImport $bookings): RedirectResponse
    {
        abort_unless(in_array($kind, ['rooms', 'bookings'], true), 404);
        abort_unless($request->user()?->can($kind === 'rooms' ? 'rooms.manage' : 'bookings.create') === true, 403);
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);

        $file = $request->file('file');
        abort_if($file === null || is_array($file), 422);
        $rows = CsvReader::read($file, $kind === 'rooms' ? RoomImport::COLUMNS : BookingImport::REQUIRED);
        $preview = $kind === 'rooms' ? $rooms->preview($rows) : $bookings->preview($rows);
        $token = Str::random(32);
        Cache::put($this->key($token), ['kind' => $kind, 'preview' => $preview], now()->addMinutes(30));

        Inertia::flash('import', ['kind' => $kind, 'token' => $token, 'rows' => $preview]);

        return back();
    }

    public function confirm(Request $request, RoomImport $rooms, BookingImport $bookings, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:32']]);
        $stored = Cache::pull($this->key((string) $data['token']));

        if (! is_array($stored)) {
            throw ValidationException::withMessages(['file' => __('This preview has expired. Please upload the file again.')]);
        }

        abort_unless($user->can($stored['kind'] === 'rooms' ? 'rooms.manage' : 'bookings.create'), 403);

        if ($stored['kind'] === 'rooms') {
            $count = $rooms->save($stored['preview'], $user);
            Inertia::flash('toast', ['type' => 'success', 'message' => __(':count rooms imported.', ['count' => $count])]);
        } else {
            $result = $bookings->save($stored['preview'], $user);
            Inertia::flash('import_result', $result);
            Inertia::flash('toast', ['type' => $result['refused'] === [] ? 'success' : 'warning', 'message' => __(':saved bookings imported, :refused refused.', ['saved' => $result['saved'], 'refused' => count($result['refused'])])]);
        }

        return back();
    }

    public function draftPack(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['answers' => ['required', 'array'], 'answers.*' => ['nullable', 'string', 'max:1500']]);

        try {
            $count = app(\App\Domains\Onboarding\PackDrafter::class)->draft(array_map(fn ($v): string => (string) $v, array_filter((array) $data['answers'])), $user);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['answers' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft ready (:count entries). Review it, then publish it.', ['count' => $count])]);

        return to_route('hotel.assistant.knowledge');
    }

    public function requestGoLive(#[CurrentUser] User $user): RedirectResponse
    {
        try {
            app(\App\Domains\Onboarding\GoLive::class)->request($user);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['golive' => $e->getMessage()]);
        }

        return back();
    }

    private function key(string $token): string
    {
        return 'setup-import:'.$this->context->id().':'.$token;
    }
}
