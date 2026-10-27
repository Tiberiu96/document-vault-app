<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\User;
use App\Services\LanguageManager;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LanguageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LanguageSeeder::class);
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $user->is_super_admin = true;
        $user->save();

        return $user;
    }

    public function test_workspace_user_cannot_manage_platform_languages(): void
    {
        $this->expectException(HttpException::class);
        app(LanguageManager::class)->updateAvailability(User::factory()->create(), Language::where('locale', 'hu')->first()->id, false, true);
    }

    public function test_default_cannot_be_disabled_without_replacement(): void
    {
        $this->expectException(ValidationException::class);
        app(LanguageManager::class)->updateAvailability($this->administrator(), Language::where('locale', 'ro')->first()->id, false, true);
    }

    public function test_default_can_be_changed_then_old_language_disabled(): void
    {
        $admin = $this->administrator();
        $manager = app(LanguageManager::class);
        $manager->makeDefault($admin, Language::where('locale', 'en')->first()->id);
        $manager->updateAvailability($admin, Language::where('locale', 'ro')->first()->id, false, true);
        $this->get('/')->assertRedirect('/en');
        $this->get('/ro')->assertNotFound();
        $this->assertSame(1, Language::where('is_default', true)->count());
    }

    public function test_selecting_the_current_default_is_idempotent(): void
    {
        app(LanguageManager::class)->makeDefault($this->administrator(), Language::where('locale', 'ro')->first()->id);
        $this->assertSame(1, Language::where('is_default', true)->count());
        $this->get('/')->assertRedirect('/ro');
    }

    public function test_record_without_deployed_translations_cannot_be_activated(): void
    {
        $language = Language::forceCreate(['locale' => 'de', 'display_name' => 'German', 'native_name' => 'Deutsch', 'default_currency' => 'EUR']);
        $this->expectException(ValidationException::class);
        app(LanguageManager::class)->updateAvailability($this->administrator(), $language->id, true, true);
    }

    public function test_translation_readiness_is_required_for_public_availability(): void
    {
        app(LanguageManager::class)->updateAvailability($this->administrator(), Language::where('locale', 'hu')->first()->id, false, false);
        $this->get('/hu')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('/hu');
    }
}
