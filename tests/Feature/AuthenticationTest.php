<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LanguageSeeder::class);
    }

    public function test_localized_login_is_visible_and_private_pages_redirect_guests(): void
    {
        foreach (['ro' => ['autentificare', 'panou'], 'en' => ['login', 'dashboard'], 'hu' => ['bejelentkezes', 'iranyitopult']] as $locale => [$login, $dashboard]) {
            $this->get('/'.$locale)->assertSee('/'.$locale.'/'.$login);
            $this->get('/'.$locale.'/'.$login)->assertOk()->assertSee('type="password"', false)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->get('/'.$locale.'/'.$dashboard)->assertRedirect('/'.$locale.'/'.$login);
        }
    }

    public function test_login_normalizes_email_and_regenerates_session(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test', 'password' => Hash::make('Example-Only-Password-938!')]);
        $this->get('/en/login');
        $before = session()->getId();
        $this->post('/en/login', ['email' => ' MEMBER@example.test ', 'password' => 'Example-Only-Password-938!'])->assertRedirect('/en/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId());
        $this->get('/en/dashboard')->assertOk()->assertSee($user->name)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_invalid_credentials_do_not_flash_passwords(): void
    {
        $this->from('/ro/autentificare')->post('/ro/autentificare', ['email' => 'missing@example.test', 'password' => 'fake-example-wrong-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertArrayNotHasKey('password', session()->getOldInput());
    }

    public function test_account_lockout_is_shared_between_ip_addresses_and_returns_429(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])
                ->post('/ro/autentificare', ['email' => 'missing@example.test', 'password' => 'fake-example'])->assertSessionHasErrors('email');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
            ->post('/ro/autentificare', ['email' => 'missing@example.test', 'password' => 'fake-example'])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertGuest();
    }

    public function test_logout_invalidates_session_and_get_logout_is_not_allowed(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/ro/deconectare')->assertStatus(405);
        $this->post('/ro/deconectare')->assertRedirect('/ro');
        $this->assertGuest();
        $this->get('/ro/panou')->assertRedirect('/ro/autentificare');
    }

    public function test_guest_only_login_redirects_an_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create())->get('/hu/bejelentkezes')->assertRedirect('/hu/iranyitopult');
    }

    public function test_dashboard_does_not_show_other_workspace_data_or_bypass_for_super_admin(): void
    {
        $user = User::factory()->create();
        $user->is_super_admin = true;
        $user->save();
        $other = User::factory()->create();
        $own = Workspace::forceCreate(['owner_id' => $user->id, 'name' => 'My example workspace', 'type' => 'personal']);
        $own->members()->attach($user, ['role' => 'owner']);
        $private = Workspace::forceCreate(['owner_id' => $other->id, 'name' => 'Confidential example workspace', 'type' => 'personal']);
        // Chiar si un membership inconsistent nu publica un workspace personal strain.
        $private->members()->attach($user, ['role' => 'admin']);
        $this->assertFalse(Gate::forUser($user)->allows('view', $private));
        $this->actingAs($user)->get('/ro/panou')->assertOk()->assertSee('My example workspace')->assertDontSee('Confidential example workspace');
    }

    public function test_array_inputs_cannot_break_login_rendering(): void
    {
        $this->from('/ro/autentificare')->post('/ro/autentificare', ['email' => ['unexpected'], 'password' => ['unexpected']])->assertSessionHasErrors(['email', 'password']);
        $this->get('/ro/autentificare')->assertOk();
    }

    public function test_login_and_dashboard_are_excluded_from_public_discovery(): void
    {
        $this->get('/sitemap.xml')->assertDontSee('/autentificare')->assertDontSee('/login')->assertDontSee('/panou')->assertDontSee('/dashboard');
        $this->get('/llms.txt')->assertDontSee('/autentificare')->assertDontSee('/login')->assertDontSee('/panou');
    }
}
