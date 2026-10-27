<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulgarianLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LanguageSeeder::class);
    }

    public function test_bulgarian_is_available_with_cyrillic_metadata_and_fonts(): void
    {
        $this->get('/bg')->assertOk()->assertSee('lang="bg"', false)->assertSee('Важните')
            ->assertSee('bg_BG')->assertSee('noto-serif-bg.woff2')->assertSee('/bg/vhod')->assertSee('/bg/registratsiya');
        $this->get('/bg/tseni')->assertOk()->assertSee('EUR');
        $this->get('/ro')->assertSee('hreflang="bg"', false);
        $this->assertDatabaseHas('languages', ['locale' => 'bg', 'default_currency' => 'EUR', 'is_active' => true]);
        $this->get('/')->assertRedirect('/ro');
    }

    public function test_bulgarian_signup_creates_a_localized_personal_workspace(): void
    {
        $this->post('/bg/registratsiya', [
            'account_type' => 'pf', 'name' => 'Тестов Потребител', 'email' => 'bg-example@example.test',
            'password' => 'Example-Only-Password-938!', 'password_confirmation' => 'Example-Only-Password-938!',
        ])->assertRedirect('/bg/tablo');
        $user = User::sole();
        $this->assertSame('Тестов Потребител', $user->name);
        $this->assertSame('Лично пространство', $user->workspaces()->sole()->name);
        $this->get('/bg/tablo')->assertOk()->assertSee('Добре дошъл')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->post('/bg/izhod')->assertRedirect('/bg');
        $this->get('/bg/tablo')->assertRedirect('/bg/vhod');
    }

    public function test_disabled_bulgarian_disappears_from_routes_and_discovery(): void
    {
        Language::where('locale', 'bg')->update(['is_active' => false]);
        $this->get('/bg')->assertNotFound();
        $this->get('/bg/registratsiya')->assertNotFound();
        $this->get('/ro')->assertDontSee('hreflang="bg"', false);
        $this->get('/sitemap.xml')->assertDontSee('/bg');
        $this->get('/llms.txt')->assertDontSee('/bg');
    }
}
