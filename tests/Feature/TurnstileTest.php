<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountMail;
use App\Services\Security\Turnstile;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TurnstileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LanguageSeeder::class);
        config(['app.url' => 'https://vaulty.example', 'turnstile.enabled' => true, 'turnstile.site_key' => 'site-key-example-only', 'turnstile.secret_key' => 'secret-key-example-only', 'turnstile.hostnames' => ['vaulty.example']]);
        Http::preventStrayRequests();
        Notification::fake();
    }

    private function success(string $action): array
    {
        return ['success' => true, 'hostname' => 'vaulty.example', 'action' => $action];
    }

    private function loginData(): array
    {
        User::factory()->create(['email' => 'member@example.test', 'password' => Hash::make('Example-Only-Password-938!')]);

        return ['email' => 'member@example.test', 'password' => 'Example-Only-Password-938!', 'cf-turnstile-response' => 'test-token-example'];
    }

    public function test_disabled_widget_makes_no_external_requests_and_keeps_existing_login(): void
    {
        config(['turnstile.enabled' => false]);
        $this->get('/ro/autentificare')->assertOk()->assertDontSee('data-turnstile-field', false)->assertDontSee('challenges.cloudflare.com');
        $data = $this->loginData();
        unset($data['cf-turnstile-response']);
        $this->post('/ro/autentificare', $data)->assertRedirect('/ro/panou');
        Http::assertNothingSent();
    }

    public function test_enabled_forms_render_only_public_key_and_fixed_action_in_four_locales(): void
    {
        foreach (['ro' => ['autentificare', 'inregistrare', 'parola-uitata'], 'en' => ['login', 'register', 'forgot-password'], 'hu' => ['bejelentkezes', 'regisztracio', 'elfelejtett-jelszo'], 'bg' => ['vhod', 'registratsiya', 'zabravena-parola']] as $locale => $paths) {
            foreach ($paths as $path) {
                $this->get('/'.$locale.'/'.$path)->assertOk()->assertSee('site-key-example-only')->assertSee('data-turnstile-field', false)->assertDontSee('secret-key-example-only')->assertDontSee('turnstile.waiting');
            }
        }
        Http::assertNothingSent();
    }

    public function test_missing_malformed_and_oversized_tokens_fail_before_cloudflare_or_authentication(): void
    {
        $data = $this->loginData();
        foreach ([null, [], str_repeat('x', 2049)] as $token) {
            $this->post('/ro/autentificare', [...$data, 'cf-turnstile-response' => $token])->assertSessionHasErrors('turnstile')->assertSessionMissing('_old_input.cf-turnstile-response')->assertSessionMissing('_old_input.password');
            $this->assertGuest();
        }
        Http::assertNothingSent();
    }

    public function test_valid_token_allows_login_and_only_sends_secret_and_token_to_cloudflare(): void
    {
        Http::fake([Turnstile::ENDPOINT => Http::response($this->success('login'))]);
        $this->post('/ro/autentificare', $this->loginData())->assertRedirect('/ro/panou');
        $this->assertAuthenticated();
        Http::assertSent(fn ($request) => $request->url() === Turnstile::ENDPOINT && $request['secret'] === 'secret-key-example-only' && $request['response'] === 'test-token-example' && ! isset($request['email']) && ! isset($request['password']) && ! isset($request['remoteip']));
        Http::assertSentCount(1);
    }

    public function test_wrong_hostname_action_and_non_boolean_success_are_rejected(): void
    {
        $data = $this->loginData();
        $replies = [['success' => true, 'hostname' => 'evil.example', 'action' => 'login'], ['success' => true, 'hostname' => 'vaulty.example', 'action' => 'register'], ['success' => 'true', 'hostname' => 'vaulty.example', 'action' => 'login']];
        $sequence = Http::sequence();
        foreach ($replies as $reply) {
            $sequence->push($reply);
        }
        Http::fake([Turnstile::ENDPOINT => $sequence]);
        foreach ($replies as $reply) {
            $this->post('/ro/autentificare', $data)->assertSessionHasErrors('turnstile');
            $this->assertGuest();
        }

    }

    public function test_expired_or_replayed_token_is_rejected_without_flashing_it(): void
    {
        Http::fake([Turnstile::ENDPOINT => Http::response(['success' => false, 'error-codes' => ['timeout-or-duplicate']])]);
        $this->post('/ro/autentificare', $this->loginData())->assertSessionHasErrors('turnstile')->assertSessionMissing('_old_input.cf-turnstile-response');
        $this->assertGuest();
    }

    public function test_provider_outage_fails_closed_without_exposing_provider_body(): void
    {
        Http::fake([Turnstile::ENDPOINT => Http::response('PRIVATE-PROVIDER-BODY', 503)]);
        $response = $this->postJson('/ro/autentificare', $this->loginData())->assertUnprocessable()->assertJsonValidationErrors('turnstile');
        $response->assertDontSee('PRIVATE-PROVIDER-BODY');
        $this->assertGuest();
    }

    public function test_connection_failure_and_missing_configuration_fail_closed(): void
    {
        Http::fake([Turnstile::ENDPOINT => Http::failedConnection()]);
        $data = $this->loginData();
        $this->post('/ro/autentificare', $data)->assertSessionHasErrors('turnstile');
        $this->assertGuest();
        config(['turnstile.secret_key' => '']);
        $this->post('/ro/autentificare', $data)->assertSessionHasErrors('turnstile');
    }

    public function test_validated_registration_uses_register_action_and_creates_normal_personal_workspace(): void
    {
        Http::fake([Turnstile::ENDPOINT => Http::response($this->success('register'))]);
        $this->post('/ro/inregistrare', ['name' => 'Test member', 'email' => 'new@example.test', 'password' => 'Example-Only-Password-938!', 'password_confirmation' => 'Example-Only-Password-938!', 'account_type' => 'pf', 'cf-turnstile-response' => 'test-register-token'])->assertRedirect('/ro/panou');
        $user = User::where('email', 'new@example.test')->sole();
        $this->assertFalse($user->is_super_admin);
        $this->assertSame(1, $user->workspaces()->count());
    }

    public function test_reset_email_is_not_dispatched_without_valid_turnstile(): void
    {
        $user = User::factory()->create();
        Http::fake([Turnstile::ENDPOINT => Http::sequence()->push(['success' => false])->push($this->success('password_reset'))]);
        $this->post('/ro/parola-uitata', ['email' => $user->email, 'cf-turnstile-response' => 'test-reset-token'])->assertSessionHasErrors('turnstile');
        Notification::assertNothingSent();
        $this->post('/ro/parola-uitata', ['email' => $user->email, 'cf-turnstile-response' => 'test-reset-new-token'])->assertSessionHasNoErrors();
        Notification::assertSentTo($user, AccountMail::class);
    }

    public function test_public_test_keys_are_never_allowed_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['turnstile.site_key' => '1x00000000000000000000AA', 'turnstile.secret_key' => '1x0000000000000000000000000000000AA']);
        $this->assertFalse(app(Turnstile::class)->configured());
        $this->post('/ro/autentificare', $this->loginData())->assertSessionHasErrors('turnstile');
        Http::assertNothingSent();
    }

    public function test_expected_hostname_comes_from_trusted_app_url_not_request_host(): void
    {
        config(['turnstile.hostnames' => []]);
        Http::fake([Turnstile::ENDPOINT => Http::response(['success' => true, 'hostname' => 'attacker.example', 'action' => 'login'])]);
        $this->post('https://attacker.example/ro/autentificare', $this->loginData())->assertSessionHasErrors('turnstile');
        $this->assertGuest();
        $this->assertSame(['vaulty.example'], app(Turnstile::class)->hostnames());
    }
}
