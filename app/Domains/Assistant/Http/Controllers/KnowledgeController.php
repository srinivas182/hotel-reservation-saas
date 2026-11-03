<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Http\Controllers;

use App\Domains\Assistant\Knowledge\KnowledgeEntry;
use App\Domains\Assistant\Knowledge\KnowledgePack;
use App\Domains\Assistant\Knowledge\KnowledgeSection;
use App\Domains\Assistant\Knowledge\KnowledgeVersion;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Hotel\Support\Locales;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The hotel information pack: draft entries per language, publishing and
 * a preview of exactly what the assistant sees.
 */
final class KnowledgeController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(Request $request, KnowledgePack $pack): Response
    {
        $enabled = HotelSettings::current()->enabled_locales ?? [$this->context->hotel()->default_locale];
        $locale = in_array($request->query('locale'), $enabled, true) ? (string) $request->query('locale') : $enabled[0];

        return Inertia::render('hotel/assistant-knowledge', [
            'locale' => $locale,
            'locales' => array_map(fn (string $code): array => ['code' => $code, 'label' => Locales::SUPPORTED[$code] ?? $code], $enabled),
            'sections' => collect(KnowledgeSection::cases())->map(fn (KnowledgeSection $s): array => ['value' => $s->value, 'label' => $s->label()])->values(),
            'entries' => KnowledgeEntry::query()->where('locale', $locale)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (KnowledgeEntry $e): array => ['section' => $e->section->value, 'question' => $e->question, 'content' => $e->content])->values(),
            'versions' => KnowledgeVersion::query()->with('publisher')->orderByDesc('version')->limit(10)->get()
                ->map(fn (KnowledgeVersion $v): array => [
                    'version' => $v->version,
                    'by' => $v->publisher?->name,
                    'at' => $v->published_at->setTimezone($this->context->hotel()->timezone)->format('Y-m-d H:i'),
                ])->values(),
            'preview' => Inertia::optional(fn (): string => $pack->compile($locale)),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $enabled = HotelSettings::current()->enabled_locales ?? [$this->context->hotel()->default_locale];

        $data = $request->validate([
            'locale' => ['required', Rule::in($enabled)],
            'entries' => ['present', 'array', 'max:200'],
            'entries.*.section' => ['required', Rule::enum(KnowledgeSection::class)],
            'entries.*.question' => ['nullable', 'string', 'max:300', 'required_if:entries.*.section,faq'],
            'entries.*.content' => ['required', 'string', 'max:3000'],
        ]);

        DB::transaction(function () use ($data): void {
            KnowledgeEntry::query()->where('locale', $data['locale'])->delete();

            foreach (array_values($data['entries']) as $index => $entry) {
                KnowledgeEntry::query()->create([
                    'locale' => $data['locale'],
                    'section' => $entry['section'],
                    'question' => $entry['section'] === KnowledgeSection::Faq->value ? trim((string) $entry['question']) : null,
                    'content' => trim((string) $entry['content']),
                    'sort_order' => $index,
                ]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft saved. Publish it when it is ready.')]);

        return back();
    }

    public function publish(KnowledgePack $pack, #[CurrentUser] User $user): RedirectResponse
    {
        $version = $pack->publish($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Version :version published. The assistant now uses it.', ['version' => $version->version])]);

        return back();
    }
}
