<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Onboarding\Legal;
use App\Domains\Onboarding\Models\LegalDocument;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

final class LegalController extends Controller
{
    public function show(string $kind, Request $request): View
    {
        abort_unless(in_array($kind, LegalDocument::KINDS, true), 404);
        $document = LegalDocument::current($kind) ?? abort(404);
        $locale = in_array($request->query('lang'), ['fr', 'en'], true) ? (string) $request->query('lang') : app()->getLocale();

        return view('legal.show', [
            'title' => LegalDocument::label($kind),
            'version' => $document->version,
            'html' => Str::markdown($document->text($locale), ['html_input' => 'escape', 'allow_unsafe_links' => false]),
        ]);
    }

    public function acceptPage(#[CurrentUser] User $user, Legal $legal): Response|RedirectResponse
    {
        $missing = $legal->missing($user);

        if ($missing === []) {
            return to_route('dashboard');
        }

        return Inertia::render('auth/accept-legal', [
            'documents' => array_map(fn (LegalDocument $d): array => ['id' => $d->id, 'kind' => $d->kind, 'label' => LegalDocument::label($d->kind), 'version' => $d->version, 'url' => route('legal.show', $d->kind)], $missing),
        ]);
    }

    public function accept(Request $request, #[CurrentUser] User $user, Legal $legal): RedirectResponse
    {
        $request->validate(['accept' => ['accepted']]);
        $missing = $legal->missing($user);
        $legal->accept($user, $missing, $request->ip());
        Audit::record('legal.accepted', 'Legal texts accepted', causer: $user, properties: ['documents' => array_map(fn (LegalDocument $d): string => $d->kind.' v'.$d->version, $missing)]);

        return redirect()->intended(route('dashboard'));
    }
}
