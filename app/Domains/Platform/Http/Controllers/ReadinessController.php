<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Audit\Audit;
use App\Domains\GoLive\Readiness;
use App\Domains\GoLive\ReadinessCheck;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform view of every hotel's go-live readiness, and the super-admin
 * override (audited, with a reason).
 */
final class ReadinessController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        $hotels = Hotel::query()->orderBy('name')->get()->map(function (Hotel $hotel): array {
            return $this->context->run($hotel, function () use ($hotel): array {
                $checks = app(Readiness::class)->checks();
                $settings = HotelAssistantSettings::current();

                return [
                    'id' => $hotel->id,
                    'name' => $hotel->name,
                    'mode' => $settings->mode->label(),
                    'overridden' => $settings->readiness_override_at !== null,
                    'override_reason' => $settings->readiness_override_reason,
                    'missing' => array_values(array_map(fn (ReadinessCheck $c): string => $c->label, array_filter($checks, fn (ReadinessCheck $c): bool => $c->required && ! $c->ok))),
                    'recommended_missing' => count(array_filter($checks, fn (ReadinessCheck $c): bool => ! $c->required && ! $c->ok)),
                ];
            });
        })->values();

        return Inertia::render('platform/readiness', ['hotels' => $hotels]);
    }

    public function override(Request $request, Hotel $hotel, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless($user->isSuperAdmin(), 403);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300'], 'remove' => ['nullable', 'boolean']]);
        $remove = (bool) ($data['remove'] ?? false);

        if (! $remove && trim((string) ($data['reason'] ?? '')) === '') {
            return back()->withErrors(['reason' => __('Give a reason for going live without every required check.')]);
        }

        $this->context->run($hotel, function () use ($remove, $data, $user, $hotel): void {
            HotelAssistantSettings::current()->forceFill($remove
                ? ['readiness_override_at' => null, 'readiness_override_reason' => null, 'readiness_override_by' => null]
                : ['readiness_override_at' => now(), 'readiness_override_reason' => trim((string) $data['reason']), 'readiness_override_by' => $user->getKey()])->save();

            Audit::record($remove ? 'go_live.override_removed' : 'go_live.override', $remove ? 'Go-live override removed' : 'Go-live allowed without every required check', subject: $hotel, causer: $user, properties: ['reason' => $data['reason'] ?? null]);
        });

        return back();
    }
}
