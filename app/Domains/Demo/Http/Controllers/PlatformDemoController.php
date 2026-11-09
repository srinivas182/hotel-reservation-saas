<?php

declare(strict_types=1);

namespace App\Domains\Demo\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Demo\DemoHotels;
use App\Domains\Demo\Mail\DemoLinkMail;
use App\Domains\Site\Models\Lead;
use App\Domains\Tenancy\Http\Middleware\ResolveCurrentHotel;
use App\Domains\Tenancy\Models\Hotel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform → Demo: the permanent demo hotel for sales, and private demos
 * for prospects (C).
 */
final class PlatformDemoController extends Controller
{
    public function index(): Response
    {
        $demo = DemoHotels::permanent();

        return Inertia::render('platform/demo', [
            'demo' => $demo === null ? null : ['id' => $demo->id, 'name' => $demo->name, 'login' => $demo->members()->value('email'), 'link' => $this->enterLink($demo, 60)],
            'privateDemos' => Hotel::query()->where('is_demo', true)->whereNotNull('demo_expires_at')->where('demo_expires_at', '>', now())->orderByDesc('created_at')->get()
                ->map(fn (Hotel $h): array => ['id' => $h->id, 'name' => $h->name, 'expires' => $h->demo_expires_at?->toDateTimeString(), 'lead' => Lead::query()->where('demo_hotel_id', $h->id)->value('hotel_name'), 'opened' => Lead::query()->where('demo_hotel_id', $h->id)->value('demo_opened_at')])->values(),
            'credentials' => session('demo_credentials'),
            'demoLink' => session('demo_link'),
        ]);
    }

    public function create(#[CurrentUser] User $user, DemoHotels $demos): RedirectResponse
    {
        abort_if(DemoHotels::permanent() !== null, 409);
        $made = $demos->create('Hôtel Démo');
        Audit::record('demo.permanent_created', 'Demo hotel created for sales', causer: $user);

        return back()->with('demo_credentials', ['email' => $made['user']->email, 'password' => $made['password']]);
    }

    public function password(#[CurrentUser] User $user): RedirectResponse
    {
        $demo = DemoHotels::permanent() ?? abort(404);
        $member = $demo->members()->firstOrFail();
        $password = Str::password(16, symbols: false);
        $member->forceFill(['password' => Hash::make($password)])->save();
        Audit::record('demo.password_changed', 'Demo password changed', subject: $demo, causer: $user);

        return back()->with('demo_credentials', ['email' => $member->email, 'password' => $password]);
    }

    public function reset(#[CurrentUser] User $user, DemoHotels $demos): RedirectResponse
    {
        $demos->reset(DemoHotels::permanent() ?? abort(404));

        return back();
    }

    /** C: a private demo for a lead, valid 48 hours, sent by email and as a link. */
    public function forLead(Lead $lead, #[CurrentUser] User $user, DemoHotels $demos): RedirectResponse
    {
        $made = $demos->create($lead->hotel_name, CarbonImmutable::now()->addHours(48));
        $link = $this->enterLink($made['hotel'], 48 * 60);
        $lead->update(['demo_hotel_id' => $made['hotel']->id, 'demo_sent_at' => now(), 'status' => $lead->status === 'new' ? 'contacted' : $lead->status]);

        if ($lead->email !== null) {
            Mail::to($lead->email)->queue(new DemoLinkMail($lead->id, $link));
        }
        Audit::record('demo.private_created', 'Private demo created for a lead', causer: $user, properties: ['lead' => $lead->id]);

        return back()->with('demo_link', $link);
    }

    /** The prospect (or sales) opens the demo from the signed link. */
    public function enter(Request $request, Hotel $hotel): RedirectResponse
    {
        abort_unless($hotel->is_demo && ($hotel->demo_expires_at === null || $hotel->demo_expires_at->isFuture()), 410);
        $member = $hotel->members()->firstOrFail();

        // Only accounts that belong to demo hotels can be opened this way.
        abort_if($member->hotels()->where('is_demo', false)->exists() || $member->isPlatformStaff(), 403);

        Auth::login($member);
        $request->session()->regenerate();
        $request->session()->put(ResolveCurrentHotel::SESSION_KEY, $hotel->id);
        Lead::query()->where('demo_hotel_id', $hotel->id)->whereNull('demo_opened_at')->update(['demo_opened_at' => now()]);

        return to_route('hotel.demo');
    }

    private function enterLink(Hotel $hotel, int $minutes): string
    {
        return URL::temporarySignedRoute('demo.enter', now()->addMinutes($minutes), ['hotel' => $hotel->id]);
    }
}
