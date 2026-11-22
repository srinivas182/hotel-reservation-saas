<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Tenancy\Models\Hotel;
use App\Domains\WhatsApp\Exceptions\WhatsAppConnectionFailed;
use App\Domains\WhatsApp\WhatsAppConnector;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Super admins connect a hotel's number with a Meta system-user token
 * (Meta's test number, or a number in the platform's own WhatsApp Business
 * account), without Embedded Signup. For testing and pilots; off in
 * production unless WHATSAPP_MANUAL_CONNECT=true.
 */
final class ManualWhatsAppController extends Controller
{
    public static function allowed(User $user): bool
    {
        $enabled = config('whatsapp.manual_connect');
        $enabled = $enabled === null || $enabled === '' ? ! app()->isProduction() : filter_var($enabled, FILTER_VALIDATE_BOOL);

        return $enabled && $user->isSuperAdmin();
    }

    public function store(Request $request, Hotel $hotel, WhatsAppConnector $connector, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless(self::allowed($user), 403);

        $data = $request->validate([
            'phone_number_id' => ['required', 'string', 'regex:/^\d{3,30}$/'],
            'waba_id' => ['required', 'string', 'regex:/^\d{3,30}$/'],
            'token' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'phone_number_id.regex' => __('Copy the Phone Number ID from Meta (digits only).'),
            'waba_id.regex' => __('Copy the WhatsApp Business account ID from Meta (digits only).'),
        ]);

        try {
            $account = $connector->connectWithToken(
                $hotel,
                (string) $data['waba_id'],
                (string) $data['phone_number_id'],
                trim((string) $data['token']),
                $user,
            );
        } catch (WhatsAppConnectionFailed $exception) {
            throw ValidationException::withMessages(['token' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('WhatsApp connected: :phone', ['phone' => $account->display_phone ?? $account->phone_number_id])]);

        return back();
    }
}
