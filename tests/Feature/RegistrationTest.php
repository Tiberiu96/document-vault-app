<?php

namespace Tests\Feature;

use App\Actions\Auth\RegisterUser;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LanguageSeeder::class);
    }

    private function personal(array $overrides = []): array
    {
        return array_replace([
            'account_type' => 'pf', 'name' => 'Example User', 'email' => 'register@example.test',
            'password' => 'Example-Only-Password-938!', 'password_confirmation' => 'Example-Only-Password-938!',
        ], $overrides);
    }

    private function business(array $overrides = []): array
    {
        return $this->personal(array_replace([
            'account_type' => 'pj', 'company_name' => 'Example SRL', 'cui' => 'RO12345678',
            'registration_number' => 'J40/1234/2020', 'county' => 'Cluj', 'city' => 'Cluj-Napoca',
            'street' => 'Strada Exemplu 1', 'postal_code' => '400001',
        ], $overrides));
    }

    public function test_registration_routes_are_localized_and_noindex(): void
    {
        foreach (['ro/inregistrare', 'en/register', 'hu/regisztracio'] as $path) {
            $response = $this->get('/'.$path)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $response->assertSee('name="account_type"', false)->assertSee('name="password_confirmation"', false);
            $this->assertDoesNotMatchRegularExpression('/\b(?:site|registration)\.[a-z]+\.[a-z_]+/', $response->getContent());
        }
        $this->get('/sitemap.xml')->assertDontSee('/inregistrare')->assertDontSee('/register')->assertDontSee('/regisztracio');
    }

    public function test_personal_registration_creates_owner_membership_and_secure_account(): void
    {
        Event::fake([Registered::class]);
        $this->get('/ro/inregistrare');
        $sessionId = session()->getId();
        $data = $this->personal(['email' => ' REGISTER@example.test ']);
        $this->post('/ro/inregistrare', $data)->assertRedirect('/ro/panou');
        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('register@example.test', $user->email);
        $this->assertFalse($user->is_super_admin);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check($data['password'], $user->password));
        $this->assertNotSame($data['password'], $user->password);
        $this->assertNotSame($sessionId, session()->getId());
        $workspace = $user->workspaces()->sole();
        $this->assertSame('personal', $workspace->type);
        $this->assertSame('owner', $workspace->pivot->role);
        $this->assertSame($user->id, $workspace->owner_id);
        $this->assertSame($workspace->id, session('active_workspace_id'));
        $this->assertDatabaseCount('companies', 0);
        Event::assertDispatched(Registered::class);
    }

    public function test_business_registration_creates_two_workspaces_and_company_in_one_flow(): void
    {
        $this->post('/hu/regisztracio', $this->business(['name' => 'Márton Őri']))->assertRedirect('/hu/iranyitopult');
        $user = User::sole();
        $this->assertSame('Márton Őri', $user->name);
        $this->assertDatabaseCount('workspaces', 2);
        $this->assertDatabaseCount('memberships', 2);
        $this->assertSame(['owner', 'owner'], $user->workspaces->pluck('pivot.role')->all());
        $company = Company::sole();
        $this->assertSame('12345678', $company->cui);
        $this->assertSame('RO', $company->country_code);
        $this->assertSame('Cluj-Napoca', $company->registered_address['city']);
        $this->assertSame('business', $company->workspace->type);
        $this->assertSame($company->workspace_id, session('active_workspace_id'));
    }

    public function test_personal_registration_ignores_company_and_privilege_injection(): void
    {
        $this->post('/en/register', $this->business([
            'account_type' => 'pf', 'is_super_admin' => true, 'email_verified_at' => now()->toISOString(),
            'owner_id' => 500, 'role' => 'super_admin', 'country_code' => 'XX',
        ]))->assertRedirect('/en/dashboard');
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('companies', 0);
        $this->assertFalse(User::sole()->is_super_admin);
        $this->assertNull(User::sole()->email_verified_at);
    }

    public function test_incomplete_business_registration_does_not_create_partial_records(): void
    {
        $this->post('/ro/inregistrare', $this->personal(['account_type' => 'pj']))->assertSessionHasErrors(['company_name', 'cui', 'registration_number', 'county', 'city', 'street']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_business_field_formats_are_validated(): void
    {
        $this->post('/ro/inregistrare', $this->business(['cui' => 'RO1234567890123', 'registration_number' => 'invalid<>', 'postal_code' => 'abc']))
            ->assertSessionHasErrors(['cui', 'registration_number', 'postal_code']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_rules_confirmation_and_byte_limit_are_enforced(): void
    {
        foreach ([['weak', 'weak'], ['Example-Only-Password-938!', 'different'], [str_repeat('Aa1!', 20), str_repeat('Aa1!', 20)]] as [$password, $confirmation]) {
            $this->post('/ro/inregistrare', $this->personal(['password' => $password, 'password_confirmation' => $confirmation]))->assertSessionHasErrors('password');
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertArrayNotHasKey('password', session()->getOldInput());
        $this->assertArrayNotHasKey('password_confirmation', session()->getOldInput());
    }

    public function test_duplicate_normalized_email_cannot_create_another_account(): void
    {
        $existing = User::factory()->create(['email' => 'register@example.test']);
        $this->post('/ro/inregistrare', $this->personal(['email' => ' REGISTER@example.test ']))->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertGuest();
        $this->assertFalse($existing->fresh()->is_super_admin);
    }

    public function test_multibyte_password_cannot_exceed_bcrypt_limit(): void
    {
        $password = str_repeat('Őa1!', 15);
        $this->post('/ro/inregistrare', $this->personal(['password' => $password, 'password_confirmation' => $password]))->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_failure_during_company_creation_rolls_back_everything(): void
    {
        Event::listen('eloquent.creating: '.Company::class, fn () => throw new RuntimeException('Example transaction failure'));
        try {
            app(RegisterUser::class)->execute($this->business(['cui' => '12345678']), 'ro');
            $this->fail('Expected transaction failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Example transaction failure', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.Company::class);
        }
        foreach (['users', 'workspaces', 'memberships', 'companies'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_unique_email_constraint_prevents_duplicate_retries(): void
    {
        $action = app(RegisterUser::class);
        $action->execute($this->personal(), 'ro');
        try {
            $action->execute($this->personal(), 'ro');
            $this->fail('Expected duplicate rejection.');
        } catch (UniqueConstraintViolationException) {
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('workspaces', 1);
            $this->assertDatabaseCount('memberships', 1);
        }
    }

    public function test_a_password_that_looks_like_a_hash_is_still_hashed_as_user_input(): void
    {
        $password = Hash::make('Example-Only-Password-938!');
        $user = app(RegisterUser::class)->execute($this->personal(['password' => $password]), 'ro');
        $this->assertNotSame($password, $user->password);
        $this->assertTrue(Hash::check($password, $user->password));
    }

    public function test_registration_rate_limit_is_shared_across_locales(): void
    {
        foreach (['/ro/inregistrare', '/en/register', '/hu/regisztracio'] as $url) {
            $this->post($url, [])->assertSessionHasErrors();
        }
        $this->post('/ro/inregistrare', $this->personal())->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_honeypot_and_array_inputs_are_rejected_without_breaking_the_form(): void
    {
        $this->post('/ro/inregistrare', $this->personal(['website' => 'https://example.invalid']))->assertSessionHasErrors('website');
        $this->post('/ro/inregistrare', $this->personal(['name' => ['unexpected'], 'cui' => ['unexpected']]))->assertSessionHasErrors('name');
        $this->get('/ro/inregistrare')->assertOk();
        $this->assertDatabaseCount('users', 0);
    }
}
