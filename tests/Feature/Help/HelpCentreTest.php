<?php

declare(strict_types=1);

use App\Domains\Help\Models\HelpArticle;
use App\Domains\Identity\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;

it('shows the help centre in French and English, with search and article pages', function (): void {
    $this->get('/help')->assertOk()->assertSee('Centre d’aide', false)->assertSee('Connecter le numéro WhatsApp de l’hôtel', false);
    $this->get('/en/help')->assertOk()->assertSee('Connecting the hotel’s WhatsApp number', false);
    $this->get('/help/connecter-whatsapp')->assertOk()->assertSee('Facebook');
    $this->get('/en/help/connecter-whatsapp')->assertOk()->assertSee('coexistence');

    $this->get('/help?q=whatsapp')->assertOk()->assertSee('Connecter le numéro WhatsApp');
    $this->get('/help?q=zzzzzz')->assertOk()->assertSee('Aucun article trouvé');
    expect(DB::table('help_searches')->where('results', 0)->value('query'))->toBe('zzzzzz');

    HelpArticle::query()->where('slug', 'evenements')->update(['published' => false]);
    $this->get('/help/evenements')->assertNotFound();
});

it('has a help article for every hotel screen', function (): void {
    $screens = [];
    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        preg_match_all("/Inertia::render\\('((hotel|staff|account)\\/[a-z0-9\\/-]+)'/", (string) file_get_contents($file->getRealPath()), $m);
        $screens = [...$screens, ...$m[1]];
    }
    $covered = HelpArticle::query()->where('published', true)->get()->flatMap(fn (HelpArticle $a) => $a->screens ?? [])->all();

    expect(array_values(array_diff(array_unique($screens), $covered)))->toBe([]);
});

it('records whether an article helped', function (): void {
    $this->post('/help/messages/feedback', ['helpful' => 1])->assertRedirect();
    $this->postJson('/help/messages/feedback', ['helpful' => 0, 'comment' => 'Pas clair pour les modèles'])->assertOk();

    $article = HelpArticle::query()->where('slug', 'messages')->sole();
    expect($article->helpful)->toBe(1)->and($article->not_helpful)->toBe(1);
});

it('gives the app the article for the current screen and searches', function (): void {
    $this->getJson('/help-panel?screen=hotel/inbox')->assertUnauthorized();
    $user = User::factory()->create(['preferred_locale' => 'fr']);

    $this->actingAs($user)->getJson('/help-panel?screen=hotel/inbox')->assertOk()->assertJsonPath('article.slug', 'messages')->assertJsonPath('article.html', fn ($html) => str_contains($html, '<li>'));
    $this->actingAs($user)->getJson('/help-panel?screen=hotel/unknown')->assertOk()->assertJsonPath('article', null);
    $this->actingAs($user)->getJson('/help-panel?q=factures&source=support')->assertOk()->assertJsonPath('results.0.slug', 'abonnement-paiement');
    expect(DB::table('help_searches')->where('source', 'support')->count())->toBe(1);
});

it('lets operations and sales write articles and see what is missing', function (): void {
    Storage::fake('local');
    $ops = User::factory()->platformAdmin(PlatformRole::Operations)->withTwoFactor()->create();
    DB::table('help_searches')->insert(['query' => 'groupe électrogène', 'locale' => 'fr', 'results' => 0, 'source' => 'app', 'created_at' => now()]);

    $this->actingAs($ops)->get(route('platform.help'))->assertOk()->assertInertia(fn ($page) => $page->where('gaps.0.q', 'groupe électrogène')->has('articles', 12));
    $this->actingAs($ops)->post(route('platform.help.store'), [
        'slug' => 'groupe-electrogene', 'category' => 'setup', 'title' => ['fr' => 'Coupures de courant', 'en' => 'Power cuts'],
        'body' => ['fr' => 'Le service fonctionne sur nos serveurs.', 'en' => 'The service runs on our servers.'], 'screens' => '', 'published' => true,
    ])->assertSessionHasNoErrors();
    $this->get('/help/groupe-electrogene')->assertOk()->assertSee('Coupures de courant');

    $markdown = $this->actingAs($ops)->postJson(route('platform.help.media'), ['file' => UploadedFile::fake()->image('step.gif')])->assertOk()->json('markdown');
    preg_match('#/help/media/([^)]+)\)#', (string) $markdown, $m);
    $this->get('/help/media/'.$m[1])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($ops)->postJson(route('platform.help.media'), ['file' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')])->assertUnprocessable();
    $this->get('/help/media/..%2F.env')->assertNotFound();

    $this->actingAs(User::factory()->platformAdmin(PlatformRole::Finance)->withTwoFactor()->create())->get(route('platform.help'))->assertForbidden();
});
