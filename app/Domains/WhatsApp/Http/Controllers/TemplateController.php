<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Http\Controllers;

use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Hotel\Support\Locales;
use App\Domains\WhatsApp\Graph\GraphException;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\StarterTemplates;
use App\Domains\WhatsApp\Templates\TemplateService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WhatsApp message templates (Owners).
 */
final class TemplateController extends Controller
{
    public function __construct(private readonly TemplateService $templates) {}

    public function index(): Response
    {
        $enabled = HotelSettings::current()->enabled_locales ?? ['en'];

        return Inertia::render('hotel/whatsapp-templates', [
            'templates' => MessageTemplate::query()->orderBy('name')->orderBy('language')->get()->map(fn (MessageTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'language' => $template->language,
                'language_label' => Locales::SUPPORTED[$template->language] ?? $template->language,
                'category' => $template->category,
                'body' => $template->body,
                'status' => $template->status->value,
                'status_label' => $template->status->label(),
                'rejection_reason' => $template->rejection_reason,
            ])->values(),
            'starters' => collect(StarterTemplates::all())->map(fn (array $starter, string $key): array => [
                'key' => $key,
                'label' => $starter['label'],
                'languages' => array_values(array_intersect(array_keys($starter['bodies']), (array) $enabled)),
            ])->values(),
            'locales' => Locales::SUPPORTED,
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'starter' => ['required', Rule::in(array_keys(StarterTemplates::all()))],
            'language' => ['required', Rule::in(Locales::codes())],
        ]);

        try {
            $template = $this->templates->createFromStarter((string) $data['starter'], (string) $data['language']);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['language' => $exception->getMessage()]);
        }

        return $this->submit($template, $user);
    }

    public function submit(MessageTemplate $template, #[CurrentUser] User $user): RedirectResponse
    {
        try {
            $ok = $this->templates->submit($template, $user);
        } catch (GraphException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', $ok
            ? ['type' => 'success', 'message' => __('Template sent to Meta for approval.')]
            : ['type' => 'error', 'message' => __('Meta refused the template: :reason', ['reason' => (string) $template->rejection_reason])]);

        return back();
    }

    public function destroy(MessageTemplate $template, #[CurrentUser] User $user): RedirectResponse
    {
        try {
            $this->templates->delete($template, $user);
        } catch (GraphException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template deleted.')]);

        return back();
    }
}
