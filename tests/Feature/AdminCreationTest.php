<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_administrator_with_a_hash_and_normalized_email(): void
    {
        $password = 'Example-Only-Password-938!';

        $this->artisan('vault:admin:create')
            ->expectsQuestion('Nume administrator', 'Administrator')
            ->expectsQuestion('Email administrator', ' ADMIN@example.test ')
            ->expectsQuestion('Parola administrator', $password)
            ->expectsQuestion('Confirma parola', $password)
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame('admin@example.test', $user->email);
        $this->assertTrue($user->is_super_admin);
        $this->assertNotSame($password, $user->password);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_rejects_a_weak_password(): void
    {
        $this->artisan('vault:admin:create')
            ->expectsQuestion('Nume administrator', 'Administrator')
            ->expectsQuestion('Email administrator', 'admin@example.test')
            ->expectsQuestion('Parola administrator', 'weak')
            ->expectsQuestion('Confirma parola', 'weak')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_rejects_mismatched_confirmation(): void
    {
        $this->artisan('vault:admin:create')
            ->expectsQuestion('Nume administrator', 'Administrator')
            ->expectsQuestion('Email administrator', 'admin@example.test')
            ->expectsQuestion('Parola administrator', 'Example-Only-Password-938!')
            ->expectsQuestion('Confirma parola', 'Different-Only-Password-938!')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_rejects_a_password_beyond_the_bcrypt_byte_limit(): void
    {
        $password = 'Example-Only-Password-938!'.str_repeat('a', 60);
        $this->artisan('vault:admin:create')
            ->expectsQuestion('Nume administrator', 'Administrator')
            ->expectsQuestion('Email administrator', 'admin@example.test')
            ->expectsQuestion('Parola administrator', $password)
            ->expectsQuestion('Confirma parola', $password)
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_does_not_promote_an_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.test']);

        $this->artisan('vault:admin:create')
            ->expectsQuestion('Nume administrator', 'Administrator')
            ->expectsQuestion('Email administrator', 'EXISTING@example.test')
            ->assertFailed();

        $this->assertFalse($user->fresh()->is_super_admin);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_rejects_non_interactive_invocation(): void
    {
        $this->artisan('vault:admin:create', ['--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_default_seeding_creates_no_account(): void
    {
        $this->seed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_global_privileges_cannot_be_mass_assigned(): void
    {
        $user = new User;
        $user->fill(['name' => 'User', 'email' => 'user@example.test', 'is_super_admin' => true]);

        $this->assertFalse((bool) $user->is_super_admin);
    }
}
