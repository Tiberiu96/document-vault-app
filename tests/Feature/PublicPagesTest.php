<?php

namespace Tests\Feature;

use App\Models\Language;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LanguageSeeder::class);
    }

    public function test_root_redirects_to_romanian_without_browser_language_override(): void
    {
        $this->withHeaders(['Accept-Language' => 'en'])->get('/')->assertRedirect('/ro');
    }

    public function test_all_public_pages_and_translated_slugs_render_in_their_locale(): void
    {
        foreach (['ro' => ['functionalitati', 'preturi', 'intrebari-frecvente'], 'en' => ['features', 'pricing', 'faq'], 'hu' => ['funkciok', 'arak', 'gyakori-kerdesek'], 'bg' => ['funktsii', 'tseni', 'chesto-zadavani-vaprosi']] as $locale => $slugs) {
            foreach (['', ...$slugs] as $slug) {
                $response = $this->get('/'.$locale.($slug ? '/'.$slug : ''));
                $response->assertOk()->assertSee('lang="'.$locale.'"', false)->assertSee(config('app.name'));
                $this->assertDoesNotMatchRegularExpression('/\bsite\.[a-z]+\.[a-z_]+/', $response->getContent());
                $dom = new \DOMDocument;
                @$dom->loadHTML($response->getContent());
                $this->assertSame(1, $dom->getElementsByTagName('h1')->length);
            }
        }
        $this->get('/ro/pricing')->assertNotFound();
        $this->get('/de')->assertNotFound();
    }

    public function test_local_pages_have_noindex_and_seo_metadata(): void
    {
        config(['app.url' => 'https://vaulty.example']);
        $this->get('/en/pricing?tracking=discard')
            ->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<link rel="canonical" href="https://vaulty.example/en/pricing">', false)
            ->assertSee('hreflang="ro" href="https://vaulty.example/ro/preturi"', false)
            ->assertSee('hreflang="hu" href="https://vaulty.example/hu/arak"', false)
            ->assertSee('hreflang="x-default"', false)
            ->assertSee('application/ld+json', false)->assertSee('og:image', false)->assertSee('twitter:card', false);
    }

    public function test_production_public_page_is_indexable_but_health_is_not(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('/ro')->assertOk()->assertHeaderMissing('X-Robots-Tag')->assertSee('content="index, follow"', false);
        $this->get('/up')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_disabled_language_is_unavailable_and_removed_from_discovery(): void
    {
        Language::where('locale', 'hu')->update(['is_active' => false]);
        $this->get('/hu')->assertNotFound();
        $this->get('/hu/arak')->assertNotFound();
        $this->get('/ro')->assertOk()->assertDontSee('hreflang="hu"', false)->assertDontSee('lang="hu"', false);
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/hu');
        $this->get('/llms.txt')->assertOk()->assertDontSee('/hu');
    }

    public function test_sitemap_contains_only_active_public_routes_and_is_valid_xml(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $this->assertCount(16, $xml->url);
        foreach (['/admin', '/api', '/demo', '/up'] as $private) {
            $response->assertDontSee($private);
        }
    }

    public function test_robots_is_environment_aware_and_llms_is_truthful(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('/robots.txt')->assertSee('Allow: /')->assertSee('/sitemap.xml');
        $this->get('/llms.txt')->assertOk()->assertSee('Currently in development')->assertDontSee('/admin')->assertDontSee('DB_PASSWORD');
    }

    public function test_demo_routes_and_links_are_removed_in_every_language(): void
    {
        foreach (['ro/demo', 'en/demo', 'hu/bemutato', 'bg/demo'] as $path) {
            $this->get('/'.$path)->assertNotFound();
        }
        foreach (['ro', 'en', 'hu', 'bg'] as $locale) {
            $this->get('/'.$locale)->assertOk()->assertDontSee('/demo')->assertDontSee('/bemutato')->assertDontSee('site.preview_ui');
        }
    }

    public function test_language_switcher_keeps_current_translated_page(): void
    {
        $this->get('/hu/arak')->assertOk()->assertSee('/ro/preturi', false)->assertSee('/en/pricing', false)->assertSee('HUF');
        $this->get('/en/pricing')->assertSee('EUR');
        $this->get('/ro/preturi')->assertSee('RON');
    }

    public function test_seeding_again_does_not_reactivate_a_disabled_language(): void
    {
        Language::where('locale', 'hu')->update(['is_active' => false]);
        $this->seed(LanguageSeeder::class);
        $this->assertDatabaseCount('languages', 4);
        $this->assertFalse(Language::where('locale', 'hu')->first()->is_active);
    }
}
