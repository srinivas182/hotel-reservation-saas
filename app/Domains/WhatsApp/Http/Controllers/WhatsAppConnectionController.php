<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Http\Controllers;

use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Exceptions\WhatsAppConnectionFailed;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\WhatsAppConnector;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The hotel's WhatsApp connection (Owners only).
 */
final class WhatsAppConnectionController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly WhatsAppConnector $connector,
    ) {}

    public function show(): Response
    {
        $hotel = $this->context->hotel();
        $account = WhatsAppAccount::query()->first();

        return Inertia::render('hotel/whatsapp', [
            'account' => $account === null ? null : [
                'status' => $account->status->value,
                'status_label' => $account->status->label(),
                'display_phone' => $account->display_phone,
                'verified_name' => $account->verified_name,
                'quality_rating' => $account->quality_rating,
                'messaging_limit' => $account->messaging_limit,
                'coexistence' => $account->coexistence,
                'connected_at' => $account->connected_at?->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
                'last_error' => $account->last_error,
            ],
            'signup' => [
                'app_id' => config('whatsapp.app_id'),
                'config_id' => config('whatsapp.config_id'),
                'graph_version' => config('whatsapp.graph_version'),
                'ready' => filled(config('whatsapp.app_id')) && filled(config('whatsapp.config_id')),
            ],
        ]);
    }

    public function connect(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:1000'],
            'waba_id' => ['required', 'string', 'regex:/^\d{3,30}$/'],
            'phone_number_id' => ['required', 'string', 'regex:/^\d{3,30}$/'],
            'coexistence' => ['required', 'boolean'],
        ]);

        try {
            $this->connector->connect(
                $this->context->hotel(),
                (string) $data['code'],
                (string) $data['waba_id'],
                (string) $data['phone_number_id'],
                (bool) $data['coexistence'],
                $user,
            );
        } catch (WhatsAppConnectionFailed $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('WhatsApp connected.')]);

        return back();
    }

    public function check(): RedirectResponse
    {
        $account = WhatsAppAccount::query()->firstOrFail();
        $ok = $this->connector->check($account);

        Inertia::flash('toast', $ok
            ? ['type' => 'success', 'message' => __('The connection works.')]
            : ['type' => 'error', 'message' => __('The connection does not work. Reconnect WhatsApp.')]);

        return back();
    }

    public function disconnect(#[CurrentUser] User $user): RedirectResponse
    {
        $this->connector->disconnect($this->context->hotel(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('WhatsApp disconnected.')]);

        return back();
    }
}
