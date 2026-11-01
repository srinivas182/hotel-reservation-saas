<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Messaging\Email\MailDriver;
use App\Domains\Messaging\Email\MailTransportFactory;
use App\Domains\Messaging\Email\Models\EmailMessage;
use App\Domains\Messaging\Email\Models\MailProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Email providers (super admins only). Credentials are write-only: they
 * are never sent back to the browser.
 */
final class MailProviderController extends Controller
{
    public function index(#[CurrentUser] User $user): Response
    {
        $this->authorizeSuperAdmin($user);

        $since = now()->subDays(30);

        $sentByProvider = EmailMessage::query()
            ->where('created_at', '>=', $since)
            ->where('status', EmailMessage::STATUS_SENT)
            ->selectRaw('mail_provider_id, count(*) as total')
            ->groupBy('mail_provider_id')
            ->pluck('total', 'mail_provider_id');

        $byCategory = EmailMessage::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('category, status, count(*) as total')
            ->groupBy('category', 'status')
            ->get();

        return Inertia::render('platform/mail-providers/index', [
            'providers' => MailProvider::query()->orderBy('priority')->orderBy('id')->get()->map(fn (MailProvider $provider): array => [
                'id' => $provider->id,
                'name' => $provider->name,
                'driver' => $provider->driver->value,
                'driver_label' => $provider->driver->label(),
                'from_address' => $provider->from_address,
                'enabled' => $provider->enabled,
                'priority' => $provider->priority,
                // Only which fields are saved, never their values for secret fields.
                'saved' => collect($provider->driver->fields())->mapWithKeys(fn (array $field): array => [
                    $field['key'] => $field['secret']
                        ? ['secret' => true, 'present' => ($provider->credentials[$field['key']] ?? '') !== '']
                        : ['secret' => false, 'value' => $provider->credentials[$field['key']] ?? ''],
                ]),
                'last_tested_at' => $provider->last_tested_at?->toIso8601String(),
                'last_test_ok' => $provider->last_test_ok,
                'last_test_error' => $provider->last_test_error,
                'sent_30_days' => (int) ($sentByProvider[$provider->id] ?? 0),
            ])->values(),
            'drivers' => collect(MailDriver::cases())->map(fn (MailDriver $driver): array => [
                'value' => $driver->value,
                'label' => $driver->label(),
                'fields' => $driver->fields(),
            ])->values(),
            'totals' => collect(EmailCategory::cases())->map(fn (EmailCategory $category): array => [
                'label' => $category->label(),
                'sent' => (int) $byCategory->where('category', $category)->where('status', EmailMessage::STATUS_SENT)->sum('total'),
                'failed' => (int) $byCategory->where('category', $category)->where('status', EmailMessage::STATUS_FAILED)->sum('total'),
                'blocked' => (int) $byCategory->where('category', $category)->where('status', EmailMessage::STATUS_BLOCKED)->sum('total'),
            ])->values(),
            'fallbackMailer' => (string) config('mail.platform_fallback'),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin($user);
        $data = $this->validated($request, null);

        $provider = MailProvider::query()->create($data);

        Audit::record('platform.mail_provider_created', 'Email provider added', properties: $this->auditData($provider));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email provider added.')]);

        return back();
    }

    public function update(Request $request, MailProvider $mailProvider, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin($user);
        $data = $this->validated($request, $mailProvider);

        $mailProvider->update($data);

        Audit::record('platform.mail_provider_updated', 'Email provider updated', properties: $this->auditData($mailProvider));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email provider updated.')]);

        return back();
    }

    public function destroy(MailProvider $mailProvider, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin($user);
        $data = $this->auditData($mailProvider);
        $mailProvider->delete();

        Audit::record('platform.mail_provider_deleted', 'Email provider removed', properties: $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email provider removed.')]);

        return back();
    }

    public function test(Request $request, MailProvider $mailProvider, MailTransportFactory $factory, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorizeSuperAdmin($user);
        $validated = $request->validate(['to' => ['required', 'email:rfc']]);

        $email = (new Email)
            ->from(new Address($mailProvider->from_address, (string) config('app.name')))
            ->to((string) $validated['to'])
            ->subject(__('Test email from :provider', ['provider' => $mailProvider->name]))
            ->text(__('This test email confirms the provider is configured correctly.'));

        try {
            $factory->make($mailProvider)->send($email, Envelope::create($email));
            $mailProvider->forceFill(['last_tested_at' => now(), 'last_test_ok' => true, 'last_test_error' => null])->save();
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Test email sent.')]);
        } catch (Throwable $exception) {
            $mailProvider->forceFill([
                'last_tested_at' => now(),
                'last_test_ok' => false,
                'last_test_error' => Str::limit($exception->getMessage(), 490),
            ])->save();
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The test email failed. See the error on the provider.')]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?MailProvider $provider): array
    {
        $driver = MailDriver::tryFrom((string) $request->input('driver'));

        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'driver' => ['required', Rule::enum(MailDriver::class)],
            'from_address' => ['required', 'email:rfc', 'max:255'],
            'enabled' => ['required', 'boolean'],
            'priority' => ['required', 'integer', 'min:1', 'max:999'],
            'credentials' => ['array'],
        ];

        foreach ($driver?->fields() ?? [] as $field) {
            // A saved secret may be left blank to keep it.
            $keepSecret = $field['secret'] && $provider !== null && $provider->driver === $driver
                && ($provider->credentials[$field['key']] ?? '') !== '';
            $rules['credentials.'.$field['key']] = [
                $field['required'] && ! $keepSecret ? 'required' : 'nullable',
                'string',
                'max:500',
            ];
        }

        $validated = $request->validate($rules);
        $driver = MailDriver::from((string) $validated['driver']);

        $credentials = [];
        foreach ($driver->fields() as $field) {
            $value = $validated['credentials'][$field['key']] ?? null;
            $credentials[$field['key']] = is_string($value) && $value !== ''
                ? $value
                : ($provider?->credentials[$field['key']] ?? '');
        }

        return [
            'name' => $validated['name'],
            'driver' => $driver,
            'from_address' => Str::lower((string) $validated['from_address']),
            'enabled' => (bool) $validated['enabled'],
            'priority' => (int) $validated['priority'],
            'credentials' => $credentials,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditData(MailProvider $provider): array
    {
        return [
            'provider_id' => $provider->id,
            'name' => $provider->name,
            'driver' => $provider->driver->value,
            'enabled' => $provider->enabled,
            'priority' => $provider->priority,
        ];
    }

    private function authorizeSuperAdmin(User $user): void
    {
        abort_unless($user->isSuperAdmin(), 403);
    }
}
