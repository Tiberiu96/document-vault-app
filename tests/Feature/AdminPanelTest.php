<?php

namespace Tests\Feature;

use App\Actions\Auth\RegisterUser;
use App\Jobs\Documents\ProcessDocument;
use App\Models\Category;
use App\Models\Document;
use App\Models\Language;
use App\Models\ProcessingRun;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AccountMail;
use App\Services\Admin\AdminMfa;
use App\Services\Admin\OperationalSummary;
use App\Services\Backup\BackupRestoreControl;
use App\Services\PlanLimitsService;
use Database\Seeders\CategorySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Example-Only-Admin-Password-938!';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->seed([LanguageSeeder::class, CategorySeeder::class, TagSeeder::class]);
        $this->mock(OperationalSummary::class)->shouldReceive('queues')->andReturn(['sizes' => ['high' => 0, 'default' => 0, 'ocr' => 0, 'ai' => 0, 'low' => 0], 'horizon' => null, 'scheduler_recent' => false]);
    }

    private function administrator(bool $mfa = true): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $user->forceFill(['is_super_admin' => true])->save();
        if ($mfa) {
            $user->forceFill(['admin_mfa_secret' => (new Google2FA)->generateSecretKey(32), 'admin_mfa_confirmed_at' => now(), 'admin_mfa_version' => 1])->save();
        }

        return $user;
    }

    private function sessionFor(User $user, bool $verified = true): array
    {
        return ['admin' => ['password_at' => now()->timestamp, 'password_fingerprint' => hash('sha256', $user->password), 'last_seen' => now()->timestamp,
            'mfa' => $verified ? ['user_id' => $user->id, 'version' => $user->admin_mfa_version, 'at' => now()->timestamp] : []]];
    }

    private function enter(User $user, bool $verified = true): static
    {
        return $this->actingAs($user, 'admin')->withSession($this->sessionFor($user, $verified));
    }

    private function otp(User $user): string
    {
        return (new Google2FA)->getCurrentOtp($user->admin_mfa_secret, intdiv(now()->timestamp, 30));
    }

    private function document(User $owner, array $attributes = []): Document
    {
        $workspace = Workspace::forceCreate(['owner_id' => $owner->id, 'name' => 'Test workspace', 'type' => 'personal']);
        $workspace->members()->attach($owner, ['role' => 'owner']);

        return Document::forceCreate(array_replace(['workspace_id' => $workspace->id, 'uploaded_by' => $owner->id, 'title' => 'PRIVATE-DOCUMENT-TITLE', 'original_name' => 'private-file.pdf', 'storage_path' => 'private-sensitive-path', 'mime_type' => 'application/pdf', 'size_bytes' => 100, 'page_count' => 1, 'sha256' => hash('sha256', random_bytes(8)), 'status' => 'stored'], $attributes));
    }

    public function test_guests_and_regular_web_sessions_cannot_enter_admin(): void
    {
        $this->get('/admin')->assertRedirect('/admin/autentificare');
        $this->actingAs($this->administrator(), 'web')->get('/admin')->assertRedirect('/admin/autentificare');
        $this->get('/ro/admin')->assertNotFound();
        $this->get('/admin/autentificare', ['Accept-Language' => 'hu'])->assertOk()->assertSee('lang="ro"', false)->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_workspace_owner_is_not_a_platform_admin_and_failed_login_is_generic(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $this->document($user);
        $this->post('/admin/autentificare', ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');
        $this->assertGuest('admin');
        $this->enter($user)->get('/admin')->assertForbidden();
    }

    public function test_password_alone_does_not_authorize_any_admin_operations(): void
    {
        $user = $this->administrator(false);
        $this->post('/admin/autentificare', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/admin/mfa/configurare');
        foreach (['/admin', '/admin/limbi', '/admin/utilizatori', '/admin/categorii', '/admin/limite'] as $path) {
            $this->get($path)->assertRedirect('/admin/mfa/configurare');
        }
        $this->post('/admin/etichete', [])->assertRedirect('/admin/mfa/configurare');
    }

    public function test_enrollment_encrypts_secrets_and_recovery_codes_and_rejects_otp_replay(): void
    {
        $user = $this->administrator(false);
        $this->enter($user, false)->get('/admin/mfa/configurare')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $pending = session('admin.enrollment');
        $secret = Crypt::decryptString($pending['secret']);
        $this->assertFalse(hash_equals($secret, $pending['secret']));
        $this->assertNull($user->fresh()->admin_mfa_secret);
        $code = (new Google2FA)->getCurrentOtp($secret, intdiv(now()->timestamp, 30));
        $this->post('/admin/mfa/configurare', ['code' => $code])->assertRedirect('/admin/mfa/coduri-recuperare');
        $user->refresh();
        $this->assertTrue(hash_equals($secret, $user->admin_mfa_secret));
        $this->assertFalse(hash_equals($secret, $user->getRawOriginal('admin_mfa_secret')));
        $this->assertSame(8, count($user->admin_recovery_hashes));
        $this->assertArrayNotHasKey('admin_mfa_secret', $user->toArray());
        $this->assertFalse(app(AdminMfa::class)->verify($user, $code));
        $encrypted = session('admin.new_recovery_codes');
        $codes = json_decode(Crypt::decryptString($encrypted), true);
        $this->assertFalse(str_contains($encrypted, $codes[0]));
        $this->get('/admin/mfa/coduri-recuperare')->assertOk();
        $this->get('/admin/mfa/coduri-recuperare')->assertRedirect('/admin/securitate');
        $this->assertTrue(app(AdminMfa::class)->verify($user, $codes[0]));
        $this->assertFalse(app(AdminMfa::class)->verify($user, $codes[0]));
        $this->assertCount(7, $user->fresh()->admin_recovery_hashes);
        $this->get('/admin')->assertRedirect('/admin/mfa/verificare');
    }

    public function test_expired_enrollment_and_bad_codes_do_not_flash_sensitive_input(): void
    {
        $user = $this->administrator(false);
        $this->enter($user, false)->get('/admin/mfa/configurare')->assertOk();
        $this->travel(601)->seconds();
        $this->post('/admin/mfa/configurare', ['code' => '123456'])->assertRedirect('/admin/autentificare');
        $this->enter($user, false)->get('/admin/mfa/configurare');
        $this->post('/admin/mfa/configurare', ['code' => 'invalid', 'current_password' => 'private-example'])->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code')->assertSessionMissing('_old_input.current_password');
        $this->assertNull($user->fresh()->admin_mfa_confirmed_at);
    }

    public function test_totp_verification_rejects_reuse_across_sessions(): void
    {
        $user = $this->administrator();
        $this->enter($user, false)->post('/admin/mfa/verificare', ['code' => $this->otp($user)])->assertRedirect('/admin');
        $this->get('/admin')->assertOk();
        $this->enter($user, false)->post('/admin/mfa/verificare', ['code' => $this->otp($user)])->assertSessionHasErrors('code');
    }

    public function test_stale_idle_password_changed_and_demoted_sessions_are_rejected(): void
    {
        $user = $this->administrator();
        $state = $this->sessionFor($user);
        $state['admin']['last_seen'] -= 1801;
        $this->actingAs($user, 'admin')->withSession($state)->get('/admin')->assertRedirect('/admin/autentificare');
        $state = $this->sessionFor($user);
        $state['admin']['password_at'] -= 3601;
        $state['admin']['mfa']['at'] -= 3601;
        $this->actingAs($user, 'admin')->withSession($state)->get('/admin')->assertRedirect('/admin/autentificare');
        $this->enter($user);
        $user->forceFill(['password' => Hash::make('Different-Example-Password-938!')])->save();
        $this->get('/admin')->assertRedirect('/admin/autentificare');
        $this->enter($user);
        $user->forceFill(['is_super_admin' => false])->save();
        $this->get('/admin')->assertForbidden();
    }

    public function test_production_requires_https_secure_cookie_and_mfa(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('/admin/autentificare')->assertStatus(503);
        config(['session.secure' => true]);
        $user = $this->administrator(false);
        $this->enter($user, false)->get('https://localhost/admin')->assertRedirect('https://localhost/admin/mfa/configurare');
    }

    public function test_admin_login_is_rate_limited_with_a_readable_romanian_page(): void
    {
        config(['admin.login_per_minute' => 1]);
        $this->post('/admin/autentificare', ['email' => 'nobody@example.test', 'password' => 'example'])->assertSessionHasErrors();
        $this->post('/admin/autentificare', ['email' => 'nobody@example.test', 'password' => 'example'])->assertStatus(429)->assertSee('Mai asteapta putin.')->assertHeader('Retry-After');
    }

    public function test_all_admin_screens_render_without_raw_private_document_or_failure_data(): void
    {
        $user = $this->administrator();
        $document = $this->document(User::factory()->create());
        ProcessingRun::forceCreate(['workspace_id' => $document->workspace_id, 'document_id' => $document->id, 'requested_by' => $document->uploaded_by, 'provider' => 'fake', 'kind' => 'ai', 'status' => 'failed', 'period' => now()->startOfMonth()->toDateString(), 'units' => 1, 'base_version' => 0, 'locale' => 'ro', 'error_code' => 'PRIVATE-ERROR', 'result' => ['text' => 'PRIVATE-OCR']]);
        DB::table('failed_jobs')->insert(['uuid' => 'fake-failure-uuid', 'connection' => 'redis', 'queue' => 'ai', 'payload' => 'PRIVATE-PAYLOAD', 'exception' => 'PRIVATE-EXCEPTION', 'failed_at' => now()]);
        $this->enter($user);
        foreach (['/admin', '/admin/backup', '/admin/notificari', '/admin/consum-asistent', '/admin/utilizatori', '/admin/spatii', '/admin/categorii', '/admin/etichete', '/admin/limbi', '/admin/limite', '/admin/securitate'] as $path) {
            $this->get($path)->assertOk()->assertSee('lang="ro"', false)->assertDontSee('PRIVATE-')->assertDontSee('private-sensitive-path');
        }
        $this->get('/admin/horizon')->assertForbidden();
        $this->get('/ro/documente/'.$document->id)->assertForbidden();
    }

    private function translations(string $ro = 'Test'): array
    {
        return ['ro' => $ro, 'en' => 'Test', 'hu' => 'Teszt ő', 'bg' => 'Тест'];
    }

    public function test_global_catalog_crud_preserves_documents_and_translations(): void
    {
        $this->enter($this->administrator());
        $this->post('/admin/categorii', ['system_key' => 'test_category', 'scope' => 'personal', 'translations' => $this->translations()])->assertSessionHasNoErrors();
        $category = Category::where('system_key', 'test_category')->sole();
        app()->setLocale('bg');
        $this->assertSame('Тест', $category->label());
        $this->patch('/admin/categorii/'.$category->id, ['translations' => $this->translations('Nou')])->assertSessionHasNoErrors();
        $this->patch('/admin/categorii/'.$category->id, ['system_key' => 'changed', 'translations' => $this->translations()])->assertSessionHasErrors('system_key');
        $document = $this->document(User::factory()->create(), ['category_id' => $category->id]);
        $this->delete('/admin/categorii/'.$category->id, ['confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($document->fresh()->category_id);
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
        $this->post('/admin/etichete', ['system_key' => 'test_tag', 'translations' => $this->translations()])->assertSessionHasNoErrors();
        $tag = Tag::where('system_key', 'test_tag')->sole();
        $document->tags()->attach($tag);
        $this->delete('/admin/etichete/'.$tag->id, ['confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(0, $document->tags()->count());
    }

    public function test_global_catalog_cannot_edit_workspace_custom_data_or_romanian_diacritics(): void
    {
        $this->enter($this->administrator());
        $document = $this->document(User::factory()->create());
        $category = Category::forceCreate(['workspace_id' => $document->workspace_id, 'name' => 'Private', 'normalized_name' => 'private']);
        $this->patch('/admin/categorii/'.$category->id, ['translations' => $this->translations()])->assertNotFound();
        $this->post('/admin/etichete', ['system_key' => 'test_tag', 'translations' => $this->translations('Greșit')])->assertSessionHasErrors('translations.ro');
    }

    public function test_languages_require_deployed_routes_and_safe_default_replacement(): void
    {
        $this->enter($this->administrator());
        $payload = ['locale' => 'de', 'display_name' => 'Germana', 'native_name' => 'Deutsch', 'default_currency' => 'EUR'];
        $this->post('/admin/limbi', $payload)->assertSessionHasNoErrors();
        $de = Language::where('locale', 'de')->sole();
        $this->assertFalse($de->is_active);
        $this->patch('/admin/limbi/'.$de->id, array_replace(array_diff_key($payload, ['locale' => true]), ['is_active' => 1, 'translation_ready' => 1]))->assertSessionHasErrors('language');
        $this->delete('/admin/limbi/'.$de->id, ['confirm' => 1])->assertSessionHasNoErrors();
        $ro = Language::where('locale', 'ro')->sole();
        $data = ['display_name' => $ro->display_name, 'native_name' => $ro->native_name, 'default_currency' => 'RON', 'is_active' => 0, 'translation_ready' => 1];
        $this->patch('/admin/limbi/'.$ro->id, $data)->assertSessionHasErrors('language');
        $en = Language::where('locale', 'en')->sole();
        $this->post('/admin/limbi/'.$en->id.'/implicit')->assertSessionHasNoErrors();
        $this->patch('/admin/limbi/'.$ro->id, $data)->assertSessionHasNoErrors();
        $this->get('/ro')->assertNotFound();
        $this->get('/admin/limbi')->assertOk()->assertSee('lang="ro"', false);
        $this->get('/sitemap.xml')->assertDontSee('/ro');
    }

    private function limits(array $replace = []): array
    {
        return array_replace(['version' => 0, 'max_pages' => 3, 'max_file_bytes' => 1048576, 'storage_bytes' => 10485760, 'monthly_ocr_pages' => 20, 'monthly_ai_calls' => 5], $replace);
    }

    public function test_free_limits_apply_immediately_and_stale_updates_are_rejected(): void
    {
        $this->enter($this->administrator());
        $this->patch('/admin/limite', $this->limits())->assertSessionHasNoErrors();
        $this->assertSame(3, app(PlanLimitsService::class)->free()['max_pages']);
        $this->assertSame(5, app(PlanLimitsService::class)->free()['monthly_ai_calls']);
        $this->assertStringContainsString('3 pagini', app(PlanLimitsService::class)->message());
        $this->patch('/admin/limite', $this->limits())->assertSessionHasErrors('version');
        $this->expectException(ValidationException::class);
        app(PlanLimitsService::class)->validateDocument(100, 4);
    }

    public function test_unlimited_monthly_quotas_are_local_only_and_infrastructure_file_cap_is_enforced(): void
    {
        $this->enter($this->administrator());
        $this->patch('/admin/limite', $this->limits(['monthly_ai_calls' => -1]))->assertSessionHasErrors('monthly_ai_calls');
        $this->patch('/admin/limite', $this->limits(['max_file_bytes' => 5242881]))->assertSessionHasErrors('max_file_bytes');
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->detectEnvironment(fn () => 'local');
        $this->patch('/admin/limite', $this->limits(['monthly_ai_calls' => -1]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('platform_plan_limits', ['plan' => 'free', 'monthly_ai_calls' => -1]);
        $this->assertSame(-1, app(PlanLimitsService::class)->free()['monthly_ai_calls']);
        $this->app['env'] = 'production';
        $this->assertFalse(app()->environment('local'), 'Unexpected global environment: '.app()->environment());
        $this->assertGreaterThanOrEqual(0, app(PlanLimitsService::class)->free()['monthly_ai_calls']);
    }

    public function test_processing_retry_cannot_bypass_workspace_policies_and_permanent_failures(): void
    {
        $admin = $this->administrator();
        $doc = $this->document(User::factory()->create());
        $run = ProcessingRun::forceCreate(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id, 'requested_by' => $doc->uploaded_by, 'provider' => 'fake', 'kind' => 'ai', 'status' => 'failed', 'period' => now()->startOfMonth()->toDateString(), 'units' => 1, 'base_version' => 0, 'locale' => 'ro', 'error_code' => 'GROQ_UNAVAILABLE']);
        $doc->forceFill(['ai_run_id' => $run->id, 'ai_status' => 'failed', 'ocr_status' => 'ready', 'ocr_text' => 'Test invoice text'])->save();
        $this->enter($admin)->post('/admin/procesari/'.$run->id.'/reluare', ['confirm' => 1])->assertForbidden();
        $doc->workspace->forceFill(['type' => 'business'])->save();
        $doc->workspace->members()->attach($admin, ['role' => 'admin']);
        $run->forceFill(['error_code' => 'INVALID_GROQ_CREDENTIALS'])->save();
        $this->post('/admin/procesari/'.$run->id.'/reluare', ['confirm' => 1])->assertSessionHasErrors('retry');
        $run->forceFill(['error_code' => 'GROQ_UNAVAILABLE'])->save();
        config(['ai.enabled' => true]);
        $this->post('/admin/procesari/'.$run->id.'/reluare', ['confirm' => 1])->assertSessionHasNoErrors();
        $this->assertNotSame($run->id, $doc->fresh()->ai_run_id);
        Queue::assertPushed(ProcessDocument::class);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'processing.retry_requested']);
    }

    public function test_console_recovery_resets_mfa_and_invalidates_existing_sessions(): void
    {
        $admin = $this->administrator();
        $this->enter($admin);
        $this->artisan('vault:admin:mfa-reset')->expectsQuestion('Email administrator', $admin->email)->expectsQuestion('Parola curenta', self::PASSWORD)->expectsConfirmation('Resetezi MFA si invalidezi toate sesiunile administrative?', 'yes')->assertSuccessful();
        $this->assertNull($admin->fresh()->admin_mfa_secret);
        $this->get('/admin')->assertRedirect('/admin/mfa/configurare');
        $this->artisan('vault:admin:mfa-reset', ['--no-interaction' => true])->assertFailed();
    }

    public function test_client_creation_uses_private_password_setup_mail_on_high_and_never_creates_admins(): void
    {
        Notification::fake();
        $this->enter($this->administrator());
        $this->get('/admin/utilizatori/creeaza')->assertOk()->assertSee('Adauga client');
        $data = ['name' => 'Test client', 'email' => 'client@example.test', 'current_password' => self::PASSWORD];
        $this->post('/admin/utilizatori', [...$data, 'is_super_admin' => true])->assertSessionHasErrors('is_super_admin');
        $this->post('/admin/utilizatori', $data)->assertRedirect('/admin/utilizatori')->assertSessionHasNoErrors();
        $client = User::where('email', 'client@example.test')->sole();
        $this->assertFalse($client->is_super_admin);
        $this->assertNull($client->email_verified_at);
        $this->assertSame(1, $client->workspaces()->where('type', 'personal')->count());
        Notification::assertSentTo($client, AccountMail::class, fn ($notification) => $notification->kind === 'reset' && $notification->queue === 'high' && $notification->afterCommit);
        Notification::assertSentTo($client, AccountMail::class, fn ($notification) => $notification->kind === 'verify');
    }

    public function test_client_email_change_requires_admin_password_and_revokes_tokens_and_verification(): void
    {
        Notification::fake();
        $client = User::factory()->create();
        $client->createToken('Test device');
        $this->enter($this->administrator());
        $this->get('/admin/utilizatori/'.$client->id.'/editeaza')->assertOk();
        $data = ['name' => 'Updated name', 'email' => 'new@example.test'];
        $this->patch('/admin/utilizatori/'.$client->id, $data)->assertSessionHasErrors('current_password');
        $this->patch('/admin/utilizatori/'.$client->id, [...$data, 'current_password' => self::PASSWORD])->assertRedirect('/admin/utilizatori')->assertSessionHasNoErrors();
        $this->assertNull($client->fresh()->email_verified_at);
        $this->assertSame(0, $client->tokens()->count());
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'client.updated', 'target_id' => (string) $client->id]);
        Notification::assertSentTo($client, AccountMail::class);
    }

    public function test_global_administrators_cannot_be_modified_deleted_or_impersonated_from_client_crud(): void
    {
        $actor = $this->administrator();
        $target = $this->administrator();
        $this->enter($actor);
        $this->get('/admin/utilizatori/'.$target->id.'/editeaza')->assertNotFound();
        $this->patch('/admin/utilizatori/'.$target->id, ['name' => 'Changed', 'email' => 'new@example.test', 'current_password' => self::PASSWORD])->assertNotFound();
        $this->post('/admin/utilizatori/'.$target->id.'/acceseaza', ['confirm' => 1, 'current_password' => self::PASSWORD])->assertNotFound();
        $this->delete('/admin/utilizatori/'.$target->id, ['confirm' => 1, 'current_password' => self::PASSWORD, 'email_confirmation' => $target->email])->assertNotFound();
        $this->assertTrue($target->fresh()->is_super_admin);
    }

    public function test_empty_client_deletion_requires_confirmation_and_preserves_clients_with_private_data(): void
    {
        $this->enter($this->administrator());
        $client = app(RegisterUser::class)->execute(['name' => 'Disposable client', 'email' => 'empty@example.test', 'password' => self::PASSWORD, 'account_type' => 'pf'], 'ro');
        $data = ['confirm' => 1, 'current_password' => self::PASSWORD, 'email_confirmation' => $client->email];
        $this->delete('/admin/utilizatori/'.$client->id, array_diff_key($data, ['confirm' => true]))->assertSessionHasErrors('confirm');
        $this->delete('/admin/utilizatori/'.$client->id, $data)->assertRedirect('/admin/utilizatori')->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['id' => $client->id]);
        $this->assertDatabaseMissing('workspaces', ['owner_id' => $client->id]);
        $owner = User::factory()->create();
        $doc = $this->document($owner);
        $this->delete('/admin/utilizatori/'.$owner->id, ['confirm' => 1, 'current_password' => self::PASSWORD, 'email_confirmation' => $owner->email])->assertSessionHasErrors('client');
        $this->assertDatabaseHas('documents', ['id' => $doc->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_impersonation_is_read_only_audited_and_stops_without_changing_client_credentials(): void
    {
        $admin = $this->administrator();
        $client = User::factory()->create();
        $document = $this->document($client);
        $originalToken = $client->remember_token;
        $this->enter($admin);
        $this->post('/admin/utilizatori/'.$client->id.'/acceseaza', ['confirm' => 1])->assertSessionHasErrors('current_password');
        $this->post('/admin/utilizatori/'.$client->id.'/acceseaza', ['confirm' => 1, 'current_password' => self::PASSWORD])->assertRedirect('/ro/panou')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($client, 'web');
        $this->get('/ro/panou')->assertOk()->assertSee('Numai consultare')->assertSee('Incheie accesul la client');
        $this->post('/ro/documente', [])->assertForbidden()->assertSee('Mod consultare client');
        $this->patch('/admin/limite', $this->limits())->assertForbidden();
        $this->get('/admin/utilizatori')->assertForbidden();
        $this->get('/ro/documente/'.$document->id)->assertOk();
        $this->post('/admin/client/opreste-accesul')->assertRedirect('/admin/utilizatori');
        $this->assertGuest('web');
        $this->get('/admin/utilizatori')->assertOk();
        $this->assertSame($originalToken, $client->fresh()->remember_token);
        foreach (['impersonation.started', 'impersonation.stopped'] as $action) {
            $this->assertDatabaseHas('admin_audit_events', ['actor_id' => $admin->id, 'action' => $action, 'target_id' => (string) $client->id]);
        }
    }

    public function test_impersonation_ends_when_admin_mfa_or_global_role_is_revoked(): void
    {
        $admin = $this->administrator();
        $client = User::factory()->create();
        $this->document($client);
        $this->enter($admin)->post('/admin/utilizatori/'.$client->id.'/acceseaza', ['confirm' => 1, 'current_password' => self::PASSWORD])->assertRedirect();
        $admin->forceFill(['is_super_admin' => false])->save();
        $this->get('/ro/panou')->assertRedirect('/admin/autentificare');
        $this->assertGuest('web');
        $this->assertGuest('admin');
    }

    public function test_workspace_filters_keep_pagination_and_long_names(): void
    {
        $admin = $this->administrator();
        $owner = User::factory()->create();
        for ($i = 0; $i < 26; $i++) {
            Workspace::forceCreate(['owner_id' => $owner->id, 'type' => 'business', 'name' => 'Potrivire '.str_repeat('contracte importante ', 5).$i]);
        }
        Workspace::forceCreate(['owner_id' => $owner->id, 'type' => 'business', 'name' => 'Exclus', 'plan_code' => 'pro']);
        $this->enter($admin);
        $query = '/admin/spatii?q=Potrivire&type=business&plan=free';
        $response = $this->get($query)->assertOk()->assertViewHas('workspaces', fn ($rows) => $rows->total() === 26 && $rows->count() === 25);
        $rows = $response->viewData('workspaces');
        $this->assertStringContainsString('type=business', $rows->nextPageUrl());
        $this->assertStringContainsString('plan=free', $rows->nextPageUrl());
        $this->get($query.'&page=2')->assertOk()->assertViewHas('workspaces', fn ($rows) => $rows->count() === 1);
        $this->get('/admin/spatii?type=invalid')->assertSessionHasErrors('type');
    }

    public function test_user_filters_and_pagination_exclude_other_roles_and_verified_accounts(): void
    {
        User::factory()->count(26)->create(['name' => 'Client cautat', 'email_verified_at' => null]);
        User::factory()->create(['name' => 'Client cautat', 'email_verified_at' => now()]);
        $this->enter($this->administrator());
        $response = $this->get('/admin/utilizatori?q=cautat&verified=no&role=client')->assertOk()->assertViewHas('users', fn ($rows) => $rows->total() === 26 && $rows->count() === 25);
        $this->assertStringContainsString('verified=no', $response->viewData('users')->nextPageUrl());
        $this->get('/admin/utilizatori?q=cautat&verified=no&role=client&page=2')->assertViewHas('users', fn ($rows) => $rows->count() === 1);
    }

    public function test_dashboard_tables_have_independent_pagination_and_filters(): void
    {
        $admin = $this->administrator();
        $document = $this->document(User::factory()->create());
        for ($i = 0; $i < 16; $i++) {
            ProcessingRun::forceCreate(['workspace_id' => $document->workspace_id, 'document_id' => $document->id, 'requested_by' => $document->uploaded_by, 'kind' => 'ai', 'provider' => 'fake', 'status' => 'failed', 'units' => 1, 'period' => now('UTC')->startOfMonth()->toDateString(), 'base_version' => 0, 'locale' => 'ro']);
            DB::table('admin_audit_events')->insert(['actor_id' => $admin->id, 'action' => 'plan.limits_updated', 'created_at' => now()]);
        }
        $this->enter($admin);
        $this->get('/admin?failed_kind=ai&audit_actor='.$admin->id)->assertOk()->assertViewHas('runs', fn ($rows) => $rows->count() === 15)->assertViewHas('events', fn ($rows) => $rows->count() === 15);
        $this->get('/admin?failed_kind=ai&audit_actor='.$admin->id.'&failed_page=2&audit_page=2')->assertOk()->assertViewHas('runs', fn ($rows) => $rows->count() === 1)->assertViewHas('events', fn ($rows) => $rows->count() === 1);
        $this->get('/admin?failed_kind=ocr')->assertViewHas('runs', fn ($rows) => $rows->total() === 0);
    }

    public function test_delivery_filters_accept_standalone_end_date_and_reject_invalid_ranges(): void
    {
        $this->enter($this->administrator());
        $this->get('/admin/notificari?status=uncertain&workspace_id=1&to=2026-12-31')->assertOk();
        $this->get('/admin/notificari?status=invalid')->assertSessionHasErrors('status');
        $this->get('/admin/notificari?from=2026-12-31&to=2026-01-01')->assertSessionHasErrors('to');
    }

    public function test_assistant_usage_requires_admin_mfa_and_aggregates_only_filtered_records(): void
    {
        $owner = User::factory()->create(['name' => 'Utilizator raport']);
        $workspace = Workspace::forceCreate(['owner_id' => $owner->id, 'type' => 'personal', 'name' => 'Spatiu raport']);
        $workspace->members()->attach($owner, ['role' => 'owner']);
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::forceCreate(['owner_id' => $otherOwner->id, 'type' => 'personal', 'name' => 'Alt spatiu']);
        $otherWorkspace->members()->attach($otherOwner, ['role' => 'owner']);

        DB::table('assistant_usage')->insert([
            ['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'provider' => 'ollama', 'model' => 'model-local', 'status' => 'completed', 'input_tokens' => 10, 'output_tokens' => 4, 'created_at' => '2026-10-15 08:00:00'],
            ['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'provider' => 'ollama', 'model' => 'model-local', 'status' => 'failed', 'input_tokens' => null, 'output_tokens' => null, 'created_at' => '2026-10-16 08:00:00'],
            ['user_id' => $otherOwner->id, 'workspace_id' => $otherWorkspace->id, 'provider' => null, 'model' => null, 'status' => 'unknown', 'input_tokens' => null, 'output_tokens' => null, 'created_at' => '2026-09-01 08:00:00'],
        ]);

        $this->get('/admin/consum-asistent')->assertRedirect('/admin/autentificare');
        $admin = $this->administrator();
        $this->enter($admin, false)->get('/admin/consum-asistent')->assertRedirect('/admin/mfa/verificare');
        $response = $this->enter($admin)->get('/admin/consum-asistent?from=2026-10-01&to=2026-10-31&user_id='.$owner->id.'&workspace_id='.$workspace->id)
            ->assertOk()->assertSee('Consum asistent AI')->assertSee('Total partial: 1 din 2 cereri.')
            ->assertSee('Utilizator raport')->assertSee('Spatiu raport')->assertDontSee('Alt spatiu');

        $totals = $response->viewData('totals');
        $this->assertSame(2, (int) $totals->requests_count);
        $this->assertSame(10, (int) $totals->input_tokens);
        $this->assertSame(4, (int) $totals->output_tokens);
        $this->assertSame(14, (int) $totals->total_tokens);
        $this->assertSame(1, (int) $totals->completed_count);
        $this->assertSame(1, (int) $totals->failed_count);
    }

    public function test_assistant_usage_filters_and_group_pagination_are_stable(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::forceCreate(['owner_id' => $owner->id, 'type' => 'personal', 'name' => 'Spatiu paginat']);
        $workspace->members()->attach($owner, ['role' => 'owner']);
        for ($index = 1; $index <= 26; $index++) {
            DB::table('assistant_usage')->insert([
                'user_id' => $owner->id, 'workspace_id' => $workspace->id, 'provider' => 'ollama',
                'model' => 'model-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'status' => 'completed',
                'input_tokens' => $index, 'output_tokens' => 1, 'created_at' => now()->addSeconds($index),
            ]);
        }

        $this->enter($this->administrator());
        $query = '/admin/consum-asistent?user_id='.$owner->id.'&workspace_id='.$workspace->id;
        $response = $this->get($query)->assertOk()->assertViewHas('usage', fn ($rows) => $rows->total() === 26 && $rows->count() === 25);
        $this->assertStringContainsString('user_id='.$owner->id, $response->viewData('usage')->nextPageUrl());
        $this->get($query.'&usage_page=2')->assertOk()->assertViewHas('usage', fn ($rows) => $rows->count() === 1);
        $this->get('/admin/consum-asistent?from=2026-12-31&to=2026-01-01')->assertSessionHasErrors('to');
        $this->get('/admin/consum-asistent?user_id=999999')->assertSessionHasErrors('user_id');
    }

    public function test_restore_requires_global_admin_mfa_and_production(): void
    {
        $this->post('/admin/backup/restaureaza')->assertRedirect('/admin/autentificare');
        $user = $this->administrator();
        $this->enter($user, false)->post('/admin/backup/restaureaza')->assertRedirect();
        $this->enter($user)->post('/admin/backup/restaureaza')->assertForbidden();
    }

    public function test_restore_rejects_wrong_password_and_never_enqueues(): void
    {
        $this->app['env'] = 'production';
        config(['backup.enabled' => true, 'backup.restore_enabled' => true, 'session.secure' => true]);
        $this->withoutMiddleware(PreventRequestForgery::class);
        $user = $this->administrator();
        $this->mock(BackupRestoreControl::class)->shouldNotReceive('request');
        $this->enter($user)->post('https://localhost/admin/backup/restaureaza', [
            'snapshot' => str_repeat('a', 64), 'scope' => 'all', 'password' => 'wrong',
            'code' => $this->otp($user), 'confirmation' => 'RESTAUREAZA', 'confirm' => '1',
        ])->assertSessionHasErrors('code');
    }

    public function test_restore_requires_fresh_code_and_queues_only_validated_scope(): void
    {
        $this->app['env'] = 'production';
        config(['backup.enabled' => true, 'backup.restore_enabled' => true, 'session.secure' => true]);
        $this->withoutMiddleware(PreventRequestForgery::class);
        $user = $this->administrator();
        $this->mock(BackupRestoreControl::class)->shouldReceive('request')->once()->with(str_repeat('a', 64), 'all', $user->id)->andReturn(str_repeat('b', 32));
        $data = ['snapshot' => str_repeat('a', 64), 'scope' => 'all', 'password' => self::PASSWORD,
            'code' => $this->otp($user), 'confirmation' => 'RESTAUREAZA', 'confirm' => '1'];
        $this->enter($user)->post('https://localhost/admin/backup/restaureaza', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'backup.restore_requested_all', 'target_type' => 'backup', 'target_id' => str_repeat('b', 32)]);
        $this->assertLessThanOrEqual(60, strlen(DB::table('admin_audit_events')->where('action', 'backup.restore_requested_all')->value('target_id')));
        $this->enter($user->fresh())->post('https://localhost/admin/backup/restaureaza', $data)->assertSessionHasErrors('code');
    }

    public function test_backup_view_has_readable_volumes_and_restore_dialog(): void
    {
        $this->mock(BackupRestoreControl::class)->shouldReceive('read')->andReturn([]);
        $this->enter($this->administrator())->get('/admin/backup')->assertOk()
            ->assertSee('Date noi identificate')->assertSee('Actualizeaza detaliile')
            ->assertSee('backup-restore-dialog', false)->assertSee('Cod MFA nou');
    }
}
