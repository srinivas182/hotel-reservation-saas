<?php

declare(strict_types=1);

namespace App\Domains\Site\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Onboarding\Models\PendingSignup;
use App\Domains\Onboarding\Models\SignupCode;
use App\Domains\Platform\PlatformAbility;
use App\Domains\Site\Models\BrandSettings;
use App\Domains\Site\Models\Lead;
use App\Domains\Site\Models\SitePage;
use App\Domains\Tenancy\Models\Hotel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform → Leads (demo requests) and Platform → Website (pages, brand).
 */
final class PlatformSiteController extends Controller
{
    public function leads(Request $request): Response
    {
        $status = $request->query('status');

        return Inertia::render('platform/leads', [
            'filter' => is_string($status) && in_array($status, Lead::STATUSES, true) ? $status : null,
            'statuses' => Lead::STATUSES,
            'demoLink' => session('demo_link'),
            'proposalLink' => session('proposal_link'),
            'plans' => \App\Domains\Billing\Models\Plan::query()->where('active', true)->orderBy('sort_order')->get()->map(fn (\App\Domains\Billing\Models\Plan $p): array => ['id' => $p->id, 'name' => $p->localizedName('fr'), 'prices' => $p->prices])->values(),
            'roiDefaults' => \App\Domains\Sales\Roi::DEFAULTS,
            'counts' => Lead::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'owners' => User::query()->whereNotNull('platform_role')->whereNull('deactivated_at')->orderBy('first_name')->get()->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->values(),
            'leads' => Lead::query()->with(['owner', 'code'])
                ->when(is_string($status) && in_array($status, Lead::STATUSES, true), fn ($q) => $q->where('status', $status))
                ->orderByRaw("case when status in ('won', 'lost') then 1 else 0 end")->latest('id')->limit(200)->get()
                ->map(function (Lead $l): array {
                    $hotel = $l->signup_code_id === null ? null : PendingSignup::query()->where('signup_code_id', $l->signup_code_id)->whereNotNull('hotel_id')->value('hotel_id');

                    return [
                        'id' => $l->id, 'name' => $l->name, 'hotel_name' => $l->hotel_name, 'country' => $l->country_code, 'city' => $l->city, 'rooms' => $l->rooms,
                        'whatsapp' => $l->whatsapp, 'email' => $l->email, 'message' => $l->message, 'contact_time' => $l->contact_time, 'locale' => $l->locale,
                        'status' => $l->status, 'lost_reason' => $l->lost_reason, 'owner_id' => $l->owner_id, 'owner' => $l->owner?->name, 'notes' => $l->notes,
                        'follow_up_on' => $l->follow_up_on?->toDateString(), 'overdue' => $l->follow_up_on !== null && $l->follow_up_on->isPast() && ! in_array($l->status, ['won', 'lost'], true),
                        'code' => $l->code?->code, 'hotel_id' => $hotel, 'created' => $l->created_at?->toDateString(),
                        'demo_sent' => $l->demo_sent_at?->toDateTimeString(), 'demo_opened' => $l->demo_opened_at?->toDateTimeString(),
                        'proposals' => \App\Domains\Sales\Models\Proposal::query()->where('lead_id', $l->id)->latest('id')->limit(5)->get()->map(fn (\App\Domains\Sales\Models\Proposal $p): array => [
                            'id' => $p->id, 'url' => url('/proposal/'.$p->token), 'created' => $p->created_at?->toDateString(), 'viewed' => $p->viewed_at?->toDateTimeString(), 'accepted' => $p->accepted_at?->toDateTimeString(),
                        ])->values(),
                    ];
                })->values(),
        ]);
    }

    public function updateLead(Request $request, Lead $lead, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Lead::STATUSES)],
            'lost_reason' => ['nullable', 'required_if:status,lost', 'string', 'max:300'],
            'owner_id' => ['nullable', 'string', Rule::exists('users', 'id')->whereNotNull('platform_role')],
            'follow_up_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $lead->update($data);
        Audit::record('lead.updated', 'Lead updated', causer: $user, properties: ['lead' => $lead->id, 'status' => $lead->status]);

        return back();
    }

    /**
     * Turn a lead into a one-use invitation code, linked so the funnel can
     * follow the lead to a paying hotel.
     */
    public function convertLead(Lead $lead, #[CurrentUser] User $user): RedirectResponse
    {
        if ($lead->signup_code_id !== null) {
            throw ValidationException::withMessages(['lead' => __('This lead already has an invitation code.')]);
        }

        $code = SignupCode::query()->create(['code' => Str::upper(Str::random(8)), 'note' => 'Lead #'.$lead->id.' · '.$lead->hotel_name, 'max_uses' => 1, 'expires_at' => now()->addDays(30), 'created_by' => $user->id]);
        $lead->update(['signup_code_id' => $code->id, 'status' => in_array($lead->status, ['new', 'contacted'], true) ? 'demo_done' : $lead->status]);
        Audit::record('lead.converted', 'Invitation code created for a lead', causer: $user, properties: ['lead' => $lead->id, 'code' => $code->code]);

        return back();
    }

    public function website(#[CurrentUser] User $user): Response
    {
        $brand = BrandSettings::current();

        return Inertia::render('platform/website', [
            'pages' => SitePage::query()->orderBy('id')->get()->map(fn (SitePage $p): array => ['id' => $p->id, 'key' => $p->key, 'title' => $p->title, 'description' => $p->description, 'body' => $p->body, 'published' => $p->published])->values(),
            'brand' => [
                'name' => $brand->name, 'tagline_fr' => $brand->tagline['fr'] ?? '', 'tagline_en' => $brand->tagline['en'] ?? '', 'primary_color' => $brand->primary_color,
                'support_email' => $brand->support_email, 'support_whatsapp' => $brand->support_whatsapp,
                'analytics_domain' => $brand->analytics_domain, 'analytics_script' => $brand->analytics_script, 'logo' => BrandSettings::cached()['logo'] ?? null,
            ],
            'canBrand' => $user->platformCan(PlatformAbility::Configuration),
        ]);
    }

    public function updatePage(Request $request, SitePage $page, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'array'], 'title.fr' => ['required', 'string', 'max:150'], 'title.en' => ['required', 'string', 'max:150'],
            'description' => ['required', 'array'], 'description.fr' => ['required', 'string', 'max:300'], 'description.en' => ['required', 'string', 'max:300'],
            'body' => ['required', 'array'], 'body.fr' => ['nullable', 'string', 'max:30000'], 'body.en' => ['nullable', 'string', 'max:30000'],
            'published' => ['required', 'boolean'],
        ]);
        $page->update(['title' => $data['title'], 'description' => $data['description'], 'body' => ['fr' => (string) ($data['body']['fr'] ?? ''), 'en' => (string) ($data['body']['en'] ?? '')], 'published' => (bool) $data['published']]);
        Audit::record('site.page_saved', 'Website page saved', causer: $user, properties: ['page' => $page->key]);

        return back();
    }

    public function updateBrand(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'tagline_fr' => ['nullable', 'string', 'max:150'], 'tagline_en' => ['nullable', 'string', 'max:150'],
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'support_email' => ['nullable', 'email', 'max:191'],
            'support_whatsapp' => ['nullable', 'string', 'max:25', 'regex:/^\+?[0-9 ().-]{8,25}$/'],
            'analytics_domain' => ['nullable', 'string', 'max:191', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'analytics_script' => ['nullable', 'url:https', 'max:255'],
            'logo' => ['nullable', 'file', 'max:512', 'mimes:png,webp,svg'],
        ]);

        $brand = BrandSettings::current();
        $values = [
            'name' => $data['name'], 'tagline' => ['fr' => (string) ($data['tagline_fr'] ?? ''), 'en' => (string) ($data['tagline_en'] ?? '')],
            'primary_color' => strtolower((string) $data['primary_color']), 'support_email' => $data['support_email'] ?? null, 'support_whatsapp' => $data['support_whatsapp'] ?? null,
            'analytics_domain' => $data['analytics_domain'] ?? null, 'analytics_script' => $data['analytics_script'] ?? null,
        ];

        $file = $request->file('logo');
        if ($file instanceof \Illuminate\Http\UploadedFile) {
            if ($file->getClientOriginalExtension() === 'svg' || str_contains((string) $file->getMimeType(), 'svg')) {
                $svg = (string) file_get_contents($file->getRealPath());
                // A logo is a picture: no scripts, event handlers or outside links.
                if (preg_match('/<script|on[a-z]+\s*=|javascript:|<foreignObject|xlink:href\s*=\s*["\'](?!#)|href\s*=\s*["\']https?:/i', $svg) === 1) {
                    throw ValidationException::withMessages(['logo' => __('This SVG contains scripts or links. Please upload a plain logo.')]);
                }
            }
            $path = 'brand/logo-'.Str::random(8).'.'.strtolower($file->getClientOriginalExtension());
            Storage::disk('local')->putFileAs('brand', $file, basename($path));
            if ($brand->logo_path !== null) {
                Storage::disk('local')->delete($brand->logo_path);
            }
            $values['logo_path'] = $path;
        }

        $brand->update($values);
        Audit::record('site.brand_saved', 'Brand saved', causer: $user, properties: ['name' => $brand->name]);

        return back();
    }
}
