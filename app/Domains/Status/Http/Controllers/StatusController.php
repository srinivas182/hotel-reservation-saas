<?php

declare(strict_types=1);

namespace App\Domains\Status\Http\Controllers;

use App\Domains\Onboarding\Models\CountryTemplate;
use App\Domains\Onboarding\Models\SignupSettings;
use App\Domains\Site\Models\BrandSettings;
use App\Domains\Status\Mail\StatusMail;
use App\Domains\Status\Models\StatusNotice;
use App\Domains\Status\Models\StatusSubscriber;
use App\Domains\Status\StatusBoard;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The public status page (French at /status, English at /en/status) and
 * email alerts (double opt-in).
 */
final class StatusController extends Controller
{
    public function index(Request $request): View
    {
        $locale = $this->locale($request);
        $country = $request->query('country');
        $country = is_string($country) && preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;

        return view('status.index', [
            'locale' => $locale, 'page' => 'status', 'title' => __('Service status'), 'description' => __('Live status of the application, WhatsApp, the assistant, emails and payments.'),
            'brand' => BrandSettings::cached() ?? ['name' => config('app.name'), 'tagline' => [], 'color' => '#4f46e5', 'logo' => null, 'support_email' => null, 'support_whatsapp' => null, 'analytics_domain' => null, 'analytics_script' => null],
            'canSignUp' => SignupSettings::current()->mode !== 'closed',
            'country' => $country,
            'countries' => CountryTemplate::query()->where('active', true)->get()->map(fn (CountryTemplate $c): array => ['code' => $c->country_code, 'name' => $c->localizedName($locale)])->sortBy('name')->values(),
            'overall' => StatusBoard::overall($country),
            'components' => StatusBoard::components($country),
            'active' => StatusNotice::query()->with('updates')->whereNull('resolved_at')->orderByDesc('starts_at')->get()->filter(fn (StatusNotice $n): bool => $n->concerns($country)),
            'history' => StatusNotice::query()->with('updates')->whereNotNull('resolved_at')->where('starts_at', '>=', now()->subDays(90))->orderByDesc('starts_at')->get(),
        ]);
    }

    public function subscribe(Request $request): RedirectResponse
    {
        $locale = $this->locale($request);
        $data = $request->validate(['email' => ['required', 'email', 'max:191'], 'country_code' => ['nullable', 'string', 'size:2', Rule::exists('country_templates', 'country_code')]]);

        $subscriber = StatusSubscriber::query()->firstOrCreate(['email' => Str::lower((string) $data['email'])], ['token' => Str::random(48), 'locale' => $locale]);
        $subscriber->update(['country_code' => $data['country_code'] ?? null, 'locale' => $locale]);
        if ($subscriber->confirmed_at === null) {
            Mail::to($subscriber->email)->queue(new StatusMail($subscriber->id, null));
        }

        return redirect($locale === 'en' ? '/en/status' : '/status')->with('status', 'check-email');
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        $locale = $this->locale($request);
        StatusSubscriber::query()->where('token', $token)->firstOrFail()->update(['confirmed_at' => now()]);

        return redirect($locale === 'en' ? '/en/status' : '/status')->with('status', 'confirmed');
    }

    public function unsubscribe(Request $request, string $token): RedirectResponse
    {
        $locale = $this->locale($request);
        StatusSubscriber::query()->where('token', $token)->delete();

        return redirect($locale === 'en' ? '/en/status' : '/status')->with('status', 'unsubscribed');
    }

    private function locale(Request $request): string
    {
        $locale = str_starts_with($request->path(), 'en') ? 'en' : 'fr';
        App::setLocale($locale);

        return $locale;
    }
}
