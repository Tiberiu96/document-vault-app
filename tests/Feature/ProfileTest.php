<?php

namespace Tests\Feature;

use App\Jobs\DeleteReplacedAvatar;
use App\Models\User;
use App\Notifications\AccountMail;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        Notification::fake();
    }

    public function test_profile_and_avatar_require_authentication(): void
    {
        $this->get('/ro/contul-meu')->assertRedirect('/ro/autentificare');
        $this->get('/ro/contul-meu/avatar')->assertRedirect('/ro/autentificare');
    }

    public function test_profile_is_localized_and_private_even_without_workspace(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['ro' => 'contul-meu', 'en' => 'my-account', 'hu' => 'fiokom', 'bg' => 'moyat-profil'] as $locale => $slug) {
            $this->get('/'.$locale.'/'.$slug)->assertOk()->assertSee('noindex, nofollow', false)->assertDontSee('profile.title')->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_profile_can_only_change_own_name_and_cannot_change_email_or_role(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create(['name' => 'Other member']);
        $this->actingAs($user)->patch('/ro/contul-meu', ['name' => 'New name', 'user_id' => $other->id, 'is_super_admin' => true])->assertRedirect();
        $this->assertSame('New name', $user->fresh()->name);
        $this->assertFalse($user->fresh()->is_super_admin);
        $this->assertSame('Other member', $other->fresh()->name);
        $email = $user->email;
        $this->patch('/ro/contul-meu', ['name' => 'Changed again', 'email' => 'changed@example.test'])->assertSessionHasErrors('email');
        $this->assertSame($email, $user->fresh()->email);
    }

    public function test_avatar_is_reencoded_private_and_replacement_removes_previous_image(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch('/ro/contul-meu', ['name' => $user->name, 'avatar' => UploadedFile::fake()->image('portrait.png', 200, 300)])->assertRedirect();
        $old = $user->fresh()->avatar_path;
        $this->assertNotNull($old);
        Storage::disk('local')->assertExists($old);
        $image = imagecreatefromstring(Storage::disk('local')->get($old));
        $this->assertSame(512, imagesx($image));
        $this->assertSame(512, imagesy($image));
        imagedestroy($image);
        $this->get('/ro/contul-meu/avatar')->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');
        $this->patch('/ro/contul-meu', ['name' => $user->name, 'avatar' => UploadedFile::fake()->image('new.jpg')])->assertRedirect();
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($user->fresh()->avatar_path);
        $this->assertArrayNotHasKey('avatar_path', $user->fresh()->toArray());
    }

    public function test_other_user_cannot_access_avatar_by_submitting_owner_id(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->patch('/ro/contul-meu', ['name' => $owner->name, 'avatar' => UploadedFile::fake()->image('avatar.jpg')])->assertRedirect();
        $other = User::factory()->create();
        $this->actingAs($other)->get('/ro/contul-meu/avatar?user_id='.$owner->id)->assertNotFound();
        Storage::disk('local')->assertExists($owner->fresh()->avatar_path);
    }

    public function test_oversized_fake_svg_and_excessive_dimension_images_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach ([UploadedFile::fake()->image('large.jpg')->size(5121), UploadedFile::fake()->createWithContent('fake.jpg', '<script>alert(1)</script>'), UploadedFile::fake()->createWithContent('avatar.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'), UploadedFile::fake()->image('wide.jpg', 4097, 10)] as $file) {
            $this->patch('/ro/contul-meu', ['name' => $user->name, 'avatar' => $file])->assertSessionHasErrors('avatar');
            $this->assertNull($user->fresh()->avatar_path);
        }
    }

    public function test_avatar_removal_and_cleanup_retry_preserve_current_avatar(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch('/ro/contul-meu', ['name' => $user->name, 'avatar' => UploadedFile::fake()->image('avatar.jpg')])->assertRedirect();
        $path = $user->fresh()->avatar_path;
        (new DeleteReplacedAvatar($user->id, $path))->handle();
        Storage::disk('local')->assertExists($path);
        $this->patch('/ro/contul-meu', ['name' => $user->name, 'remove_avatar' => 1])->assertRedirect();
        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('local')->assertMissing($path);
        (new DeleteReplacedAvatar($user->id, $path))->handle();
    }

    public function test_password_requires_current_password_then_revokes_tokens_and_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Existing-Password-123!')]);
        $user->createToken('example');
        $this->actingAs($user);
        $data = ['current_password' => 'Incorrect-Password-123!', 'password' => 'Replacement-Password-123!', 'password_confirmation' => 'Replacement-Password-123!'];
        $this->put('/ro/contul-meu/parola', $data)->assertSessionHasErrors('current_password');
        Notification::assertNothingSent();
        $this->put('/ro/contul-meu/parola', [...$data, 'current_password' => 'Existing-Password-123!'])->assertRedirect('/ro/autentificare');
        $this->assertTrue(Hash::check('Replacement-Password-123!', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertGuest('web');
        Notification::assertSentTo($user, AccountMail::class, fn ($mail) => $mail->kind === 'changed' && $mail->queue === 'high' && $mail->afterCommit);
    }
}
