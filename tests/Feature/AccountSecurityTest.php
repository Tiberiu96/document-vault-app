<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountMail;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LanguageSeeder::class);
    }

    public function test_security_pages_are_localized_private_and_do_not_leak_tokens_in_metadata(): void
    {
        foreach (['ro/parola-uitata', 'en/forgot-password', 'hu/elfelejtett-jelszo', 'bg/zabravena-parola'] as $url) {
            $response = $this->get('/'.$url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('autocomplete="username"', false);
            $this->assertStringNotContainsString('site.seo.security', $response->getContent());
        }
        $this->get('/ro/resetare-parola/example-only-token?email=sample@example.test')->assertOk();
        $this->get('/sitemap.xml')->assertDontSee('parola')->assertDontSee('verify-email');
        $this->get('/ro/autentificare')->assertSee('/ro/parola-uitata');
    }

    public function test_registration_dispatches_encrypted_verification_notification_to_high_after_commit(): void
    {
        Queue::fake();
        $this->post('/bg/registratsiya', [
            'account_type' => 'pf', 'name' => 'Примерен профил', 'email' => 'sample@example.test',
            'password' => 'Example-Password-123!', 'password_confirmation' => 'Example-Password-123!',
        ])->assertRedirect('/bg/tablo');
        Queue::assertPushedOn('high', SendQueuedNotifications::class, function ($job) {
            return $job->shouldBeEncrypted && $job->afterCommit && $job->notification->kind === 'verify'
                && $job->notification->locale === 'bg' && $job->tries === 3 && $job->timeout === 30;
        });
    }

    public function test_queue_payload_encrypts_the_reset_token(): void
    {
        $user = User::factory()->create();
        $notification = new AccountMail('reset', 'example-only-secret-token');
        $job = new SendQueuedNotifications($user, $notification, ['mail']);
        $job->afterCommit = false; // Inspecteaza payloadul in DB SQLite, fara worker sau livrare.
        app('queue')->connection('database')->push($job, '', 'high');
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString('example-only-secret-token', $payload);
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $command = app('encrypter')->decrypt($data['data']['command']);
        $this->assertStringContainsString('example-only-secret-token', $command);
    }

    public function test_signed_verification_is_owned_expiring_and_idempotent(): void
    {
        $owner = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        app()->setLocale('ro');
        $url = (new AccountMail('verify'))->actionUrl($owner);
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->actingAs($owner)->get($url)->assertRedirect('/ro/panou');
        $verifiedAt = $owner->fresh()->email_verified_at;
        $this->get($url)->assertRedirect('/ro/panou');
        $this->assertTrue($owner->fresh()->email_verified_at->equalTo($verifiedAt));
        $this->get($url.'x')->assertForbidden();
        $this->travel(61)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_email_links_use_configured_host_and_locale_even_inside_a_worker(): void
    {
        $user = User::factory()->unverified()->create();
        config(['app.url' => 'https://vaulty.example.test']);
        app()->setLocale('hu');
        $notification = new AccountMail('verify');
        app()->setLocale('ro');
        $url = $notification->actionUrl($user);
        $this->assertTrue(str_starts_with($url, 'https://vaulty.example.test/hu/email-megerosites/'));
        $this->assertTrue(str_contains($url, 'signature='));
        $notification = (new AccountMail('reset', 'example-only-token'))->locale('bg');
        $this->assertTrue(str_starts_with($notification->actionUrl($user), 'https://vaulty.example.test/bg/nova-parola/'));
    }

    public function test_resend_requires_authentication_and_is_rate_limited_across_locales(): void
    {
        Notification::fake();
        $this->post('/ro/retrimite-confirmarea')->assertRedirect('/ro/autentificare');
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->post('/ro/retrimite-confirmarea')->assertRedirect();
        $this->post('/en/resend-verification')->assertStatus(429)->assertHeader('Retry-After');
        Notification::assertSentToTimes($user, AccountMail::class, 1);
        $this->travel(61)->seconds();
        $user->markEmailAsVerified();
        $this->post('/ro/retrimite-confirmarea')->assertRedirect();
        Notification::assertSentToTimes($user, AccountMail::class, 1);
        $this->get('/ro/verifica-email')->assertRedirect('/ro/panou');
    }

    public function test_reset_request_has_same_response_for_known_and_unknown_accounts_and_shared_account_limit(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'sample@example.test']);
        $this->post('/ro/parola-uitata', ['email' => 'sample@example.test'])->assertSessionHas('security_status', trans('account_mail.reset_sent', [], 'ro'));
        $knownStatus = session('security_status');
        $this->post('/ro/parola-uitata', ['email' => 'unknown@example.test'])->assertSessionHas('security_status', $knownStatus);
        $this->post('/en/forgot-password', ['email' => ' SAMPLE@example.test '])->assertRedirect();
        $this->post('/hu/elfelejtett-jelszo', ['email' => 'sample@example.test'])->assertRedirect();
        $this->post('/bg/zabravena-parola', ['email' => 'sample@example.test'])->assertStatus(429);
        Notification::assertSentToTimes($user, AccountMail::class, 1);
    }

    public function test_password_reset_rejects_invalid_expired_wrong_owner_and_reused_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'New-Example-Password-938!', 'password_confirmation' => 'New-Example-Password-938!'];
        $this->post('/ro/resetare-parola', [...$data, 'token' => 'invalid'])->assertSessionHasErrors('email');
        $this->post('/ro/resetare-parola', [...$data, 'email' => $other->email])->assertSessionHasErrors('email');
        $this->travel(61)->minutes();
        $this->post('/ro/resetare-parola', $data)->assertSessionHasErrors('email');
        $data['token'] = Password::createToken($user);
        $this->post('/ro/resetare-parola', $data)->assertRedirect('/ro/autentificare');
        $this->post('/ro/resetare-parola', $data)->assertSessionHasErrors('email');
        Notification::assertSentTo($user, AccountMail::class, fn ($mail) => $mail->kind === 'changed' && $mail->queue === 'high');
        $this->assertTrue(Hash::check($data['password'], $user->fresh()->password));
        $this->assertGuest();
    }

    public function test_password_strength_confirmation_byte_limit_and_input_privacy(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        foreach (['weak', 'Short-123!abc', str_repeat('Őa1!', 15)] as $password) {
            $this->post('/ro/resetare-parola', ['email' => $user->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        }
        $this->post('/ro/resetare-parola', ['email' => $user->email, 'token' => $token, 'password' => 'New-Example-Password-938!', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertArrayNotHasKey('password', session()->getOldInput());
        $this->assertArrayNotHasKey('password_confirmation', session()->getOldInput());
        $this->assertArrayNotHasKey('token', session()->getOldInput());
    }

    public function test_reset_revokes_api_tokens_and_remember_cookie_and_invalidates_old_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->create(['remember_token' => 'example-only-remember']);
        $user->createToken('example-only-device');
        $token = Password::createToken($user);
        $oldHash = $user->password;
        $this->post('/ro/resetare-parola', ['email' => $user->email, 'token' => $token, 'password' => 'New-Example-Password-938!', 'password_confirmation' => 'New-Example-Password-938!'])->assertRedirect();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNotSame('example-only-remember', $user->fresh()->remember_token);
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])->get('/ro/panou')->assertRedirect('/ro/autentificare');
        $this->assertGuest();
    }

    public function test_stale_verification_and_reset_jobs_do_not_send_after_state_changes(): void
    {
        $user = User::factory()->unverified()->create();
        $verify = new AccountMail('verify');
        $this->assertTrue($verify->shouldSend($user, 'mail'));
        $user->markEmailAsVerified();
        $this->assertFalse($verify->shouldSend($user, 'mail'));
        $token = Password::createToken($user);
        $reset = new AccountMail('reset', $token);
        $this->assertTrue($reset->shouldSend($user, 'mail'));
        Password::deleteToken($user);
        $this->assertFalse($reset->shouldSend($user, 'mail'));
    }

    public function test_all_email_templates_have_translations_html_plaintext_and_escaped_content(): void
    {
        $user = User::factory()->create(['name' => '<script>example</script>']);
        $keys = array_keys(trans('account_mail', [], 'ro'));
        foreach (['ro', 'en', 'hu', 'bg'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame($keys, array_keys(trans('account_mail')));
            foreach (['verify', 'reset', 'changed'] as $kind) {
                $mail = (new AccountMail($kind, 'example-only-token'))->toMail($user);
                $html = view('mail.account', $mail->viewData)->render();
                $text = view('mail.account-text', $mail->viewData)->render();
                $this->assertStringContainsString('lang="'.$locale.'"', $html);
                $this->assertStringContainsString('font-size:16px', $html);
                $this->assertStringContainsString('&lt;script&gt;', $html);
                $this->assertStringNotContainsString('<script>', $html);
                $this->assertStringNotContainsString('account_mail.', $html.$text);
                $this->assertNotEmpty($mail->subject);
            }
        }
    }
}
