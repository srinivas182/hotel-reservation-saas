<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Http\Controllers;

use App\Domains\Messaging\Email\EmailIdentityService;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The hotel's email sender name and reply-to address.
 */
final class EmailIdentityController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EmailIdentityService $identity,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email_sender_name' => ['nullable', 'string', 'max:120', 'not_regex:/[<>@"\r\n]/'],
            'email_reply_to' => ['nullable', 'email:rfc', 'max:255'],
        ]);

        $before = $request->input('email_reply_to');
        $this->identity->update(
            $this->context->hotel(),
            $validated['email_sender_name'] ?? null,
            $validated['email_reply_to'] ?? null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => is_string($before) && $before !== ''
            ? __('Email settings saved. If you changed the reply address, confirm it from the email we sent.')
            : __('Email settings saved.')]);

        return back();
    }

    public function resend(): RedirectResponse
    {
        $this->identity->resendVerification($this->context->hotel());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Confirmation email sent again.')]);

        return back();
    }

    public function verify(string $token): Response
    {
        return Inertia::render('auth/reply-to-confirmed', [
            'confirmed' => $this->identity->verify($token),
        ]);
    }
}
