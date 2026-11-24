@extends('site.layout')

{{-- 0.37.7: the legal texts as pages of the public website (header, footer,
     contents list), instead of a bare page. --}}
@push('head')
    <meta name="robots" content="index,follow">
    <style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        .legal-hero { padding: 56px 0 36px; background: linear-gradient(180deg, var(--brand-soft), transparent); border-bottom: 1px solid var(--line); }
        .legal-hero .eyebrow { text-transform: uppercase; letter-spacing: .12em; font-size: 13px; font-weight: 700; color: var(--brand-ink); margin: 0 0 10px; }
        .legal-hero h1 { margin: 0 0 12px; font-size: clamp(30px, 4vw, 44px); line-height: 1.1; }
        .legal-hero .meta { display: flex; flex-wrap: wrap; gap: 8px 16px; color: var(--muted); font-size: 15px; margin: 0; }
        .legal-hero .meta span + span::before { content: "·"; margin-right: 16px; }
        .legal-grid { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 56px; padding: 40px 0 88px; align-items: start; }
        .legal-toc { position: sticky; top: 96px; font-size: 14px; }
        .legal-toc h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin: 0 0 10px; }
        .legal-toc ol { list-style: none; margin: 0 0 24px; padding: 0; border-left: 2px solid var(--line); }
        .legal-toc li a { display: block; padding: 5px 0 5px 14px; margin-left: -2px; border-left: 2px solid transparent; color: var(--muted); text-decoration: none; }
        .legal-toc li a:hover { color: var(--brand-ink); border-left-color: var(--brand); }
        .legal-toc .others a { display: block; padding: 4px 0; color: var(--brand-ink); }
        .legal-toc .print { margin-top: 16px; background: none; border: 1px solid var(--line); border-radius: 10px; padding: 8px 12px; font: inherit; cursor: pointer; color: inherit; }
        .legal-body { max-width: 760px; }
        .legal-body > p:first-child { font-size: 18px; color: inherit; }
        .legal-body h2 { font-size: 22px; margin: 40px 0 10px; padding-top: 8px; scroll-margin-top: 96px; }
        .legal-body h2:first-of-type { margin-top: 24px; }
        .legal-body p, .legal-body li { color: var(--muted); font-size: 16.5px; line-height: 1.7; }
        .legal-body ul { padding-left: 20px; }
        .legal-body li { margin: 4px 0; }
        .legal-body strong { color: inherit; }
        .legal-body table { width: 100%; border-collapse: collapse; margin: 12px 0 8px; font-size: 15px; }
        .legal-body th { text-align: left; background: var(--brand-soft); color: var(--brand-ink); padding: 10px 12px; font-weight: 600; }
        .legal-body td { padding: 10px 12px; border-bottom: 1px solid var(--line); color: var(--muted); vertical-align: top; }
        .legal-body a { color: var(--brand-ink); }
        .legal-contact { margin-top: 48px; padding: 20px 22px; border: 1px solid var(--line); border-radius: 16px; background: var(--brand-soft); }
        .legal-contact h2 { margin: 0 0 6px !important; padding: 0 !important; font-size: 18px !important; }
        .legal-contact p { margin: 0; }
        @media (max-width: 900px) {
            .legal-grid { grid-template-columns: 1fr; gap: 8px; }
            .legal-toc { position: static; }
            .legal-toc ol, .legal-toc .toc-title { display: none; }
        }
        @media print {
            header.top, footer.site, .chat-fab, .legal-toc, .skip { display: none !important; }
            .legal-grid { display: block; padding: 0; }
            .legal-hero { background: none; padding: 0 0 12px; }
        }
    </style>
@endpush

@section('main')
    <section class="legal-hero">
        <div class="wrap">
            <p class="eyebrow">{{ __('Legal') }}</p>
            <h1>{{ $title }}</h1>
            <p class="meta">
                <span>{{ __('Version :version', ['version' => $version]) }}</span>
                @if ($updated)<span>{{ __('Last updated :date', ['date' => $updated]) }}</span>@endif
                <span>{{ $brand['name'] }}</span>
            </p>
        </div>
    </section>
    <div class="wrap legal-grid">
        <aside class="legal-toc" aria-label="{{ __('On this page') }}">
            @if ($sections !== [])
                <h2 class="toc-title">{{ __('On this page') }}</h2>
                <ol>
                    @foreach ($sections as $section)
                        <li><a href="#{{ $section['id'] }}">{{ $section['title'] }}</a></li>
                    @endforeach
                </ol>
            @endif
            <h2>{{ __('Other legal texts') }}</h2>
            <div class="others">
                @foreach ($others as $other)
                    <a href="/legal/{{ $other }}?lang={{ $locale }}">{{ \App\Domains\Onboarding\Models\LegalDocument::label($other) }}</a>
                @endforeach
            </div>
            <button type="button" class="print" data-print>{{ __('Print or save as PDF') }}</button>
        </aside>
        <article class="legal-body">
            {!! $html !!}
            <div class="legal-contact">
                <h2>{{ __('Questions about this text?') }}</h2>
                <p>
                    @if ($brand['support_email'])
                        {!! __('Write to us at :email and we will answer within 30 days.', ['email' => '<a href="mailto:'.e($brand['support_email']).'">'.e($brand['support_email']).'</a>']) !!}
                    @else
                        {{ __('Contact us through the website and we will answer within 30 days.') }}
                    @endif
                </p>
            </div>
        </article>
    </div>
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        document.querySelector('[data-print]')?.addEventListener('click', () => window.print());
    </script>
@endsection
