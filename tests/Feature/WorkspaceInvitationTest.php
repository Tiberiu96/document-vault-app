<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\InvitationMail;
use App\Services\NotificationInbox;
use App\Services\WorkspaceInvitations;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkspaceInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $recipient;

    private Workspace $business;

    private Workspace $personal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->recipient = User::factory()->create();
        $this->workspace($this->owner, 'personal');
        $this->personal = $this->workspace($this->recipient, 'personal');
        $this->business = $this->workspace($this->owner, 'business');
        $this->actingAs($this->owner)->withSession(['active_workspace_id' => $this->business->id]);
    }

    private function workspace(User $owner, string $type): Workspace
    {
        $workspace = new Workspace;
        $workspace->forceFill(['name' => 'Firma demonstrativa', 'type' => $type, 'owner_id' => $owner->id])->save();
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        return $workspace;
    }

    private function invite(): WorkspaceInvitation
    {
        return app(WorkspaceInvitations::class)->create($this->owner, $this->business, $this->recipient->email, 'accountant');
    }

    private function mail(): InvitationMail
    {
        return Queue::pushed(SendQueuedNotifications::class)->first()->notification;
    }

    public function test_invitation_queues_encrypted_high_mail_and_personal_inbox_without_membership(): void
    {
        $this->post('/ro/membri', ['email' => ' '.mb_strtoupper($this->recipient->email).' ', 'role' => 'accountant'])->assertSessionHasNoErrors()->assertRedirect();
        $invitation = WorkspaceInvitation::sole();
        $this->assertSame($this->recipient->email, $invitation->email);
        $this->assertFalse($this->business->members()->whereKey($this->recipient->id)->exists());
        Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->notification instanceof InvitationMail && $job->queue === 'high' && $job->shouldBeEncrypted && $job->afterCommit);
        $mail = $this->mail();
        $this->assertSame(hash('sha256', $mail->token), $invitation->token_hash);
        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());
        $inbox = app(NotificationInbox::class);
        $items = $inbox->query($this->recipient, $this->personal)->get();
        $this->assertCount(1, $items);
        $this->assertStringNotContainsString($mail->token, json_encode($items->toArray()));
        $this->assertSame('invitation_received', $inbox->items($items, $this->recipient, 'ro')->first()['event']);
        $this->get('/ro/membri')->assertOk()->assertSee($this->recipient->email);
    }

    public function test_acceptance_requires_exact_verified_email_and_is_idempotent(): void
    {
        $invitation = $this->invite();
        $url = '/ro/invitatii/'.$invitation->id;
        $this->post($url)->assertForbidden();
        $this->actingAs($this->recipient);
        $this->recipient->forceFill(['email_verified_at' => null])->save();
        $this->post($url)->assertRedirect('/ro/verifica-email');
        $this->recipient->forceFill(['email_verified_at' => now()])->save();
        $this->post($url)->assertRedirect('/ro/membri');
        $this->post($url)->assertRedirect('/ro/membri');
        $this->assertSame(1, $this->business->members()->whereKey($this->recipient->id)->count());
        $this->assertSame('accountant', $this->business->members()->whereKey($this->recipient->id)->first()->pivot->role);
        $this->assertSame($this->recipient->id, $invitation->fresh()->accepted_by);
        Queue::assertPushed(SendQueuedNotifications::class, 2);
        $this->assertSame(0, app(NotificationInbox::class)->query($this->recipient, $this->personal)->whereNull('read_at')->count());
        $this->assertSame('invitation_accepted', app(NotificationInbox::class)->query($this->owner, $this->business)->sole()->data['event']);
        $this->get('/ro/membri')->assertOk()->assertDontSee('id="invite-email"', false);
        $this->post('/ro/membri', ['email' => 'other@example.test', 'role' => 'member'])->assertForbidden();
    }

    public function test_resend_rotates_token_and_suppresses_stale_queued_mail(): void
    {
        $invitation = $this->invite();
        $old = $this->mail();
        $anonymous = (new AnonymousNotifiable)->route('mail', $this->recipient->email);
        $this->assertTrue($old->shouldSend($anonymous, 'mail'));
        $this->post('/ro/membri/invitations/'.$invitation->id.'/resend')->assertSessionHasErrors();
        $this->travel(61)->seconds();
        $this->post('/ro/membri/invitations/'.$invitation->id.'/resend')->assertSessionHasNoErrors();
        $this->assertNotSame($invitation->token_hash, $invitation->fresh()->token_hash);
        $this->assertFalse($old->shouldSend($anonymous, 'mail'));
        $this->get('/ro/invitatii/'.$invitation->id.'/'.$old->token)->assertGone();
        $this->assertSame(1, $this->recipient->notifications()->count());
    }

    public function test_expired_cancelled_or_issuer_revoked_invitations_cannot_grant_access(): void
    {
        $invitation = $this->invite();
        $this->post('/ro/membri/invitations/'.$invitation->id.'/revoke')->assertRedirect();
        $this->actingAs($this->recipient)->post('/ro/invitatii/'.$invitation->id)->assertGone();
        $this->assertFalse($this->business->members()->whereKey($this->recipient->id)->exists());
        $invitation->forceFill(['revoked_at' => null, 'expires_at' => now()->subSecond()])->save();
        $this->post('/ro/invitatii/'.$invitation->id)->assertGone();
        $invitation->forceFill(['expires_at' => now()->addDay()])->save();
        $this->business->members()->detach($this->owner->id);
        $this->post('/ro/invitatii/'.$invitation->id)->assertGone();
    }

    public function test_owner_can_reissue_an_invitation_after_its_issuer_loses_management_access(): void
    {
        $admin = User::factory()->create();
        $this->business->members()->attach($admin->id, ['role' => 'admin']);
        $service = app(WorkspaceInvitations::class);
        $invitation = $service->create($admin, $this->business, $this->recipient->email, 'accountant');
        $old = $this->mail();
        $service->changeMember($this->owner, $this->business, $admin, 'member');
        $anonymous = (new AnonymousNotifiable)->route('mail', $this->recipient->email);
        $this->assertFalse($old->shouldSend($anonymous, 'mail'));
        $this->travel(61)->seconds();
        $service->resend($this->owner, $invitation);
        $this->assertSame($this->owner->id, $invitation->fresh()->invited_by);
        $this->assertFalse($old->shouldSend($anonymous, 'mail'));
        $service->accept($this->recipient, $invitation);
        $this->assertSame('accountant', $this->business->members()->whereKey($this->recipient->id)->first()->pivot->role);
        $this->assertSame('invitation_accepted', app(NotificationInbox::class)->query($this->owner, $this->business)->sole()->data['event']);
    }

    public function test_owner_and_admin_privileges_and_cross_workspace_isolation(): void
    {
        $invitation = $this->invite();
        $this->post('/ro/membri', ['email' => $this->recipient->email, 'role' => 'member'])->assertSessionHasErrors('email');
        $this->post('/ro/membri', ['email' => 'bad@example.test', 'role' => 'owner'])->assertSessionHasErrors('role');
        $admin = User::factory()->create();
        $this->business->members()->attach($admin->id, ['role' => 'admin']);
        $this->actingAs($admin)->post('/ro/membri', ['email' => 'next@example.test', 'role' => 'admin'])->assertForbidden();
        $this->patch('/ro/membri/'.$this->owner->id, ['role' => 'member'])->assertForbidden();
        $this->delete('/ro/membri/'.$this->owner->id, ['confirm' => 1])->assertForbidden();
        $other = $this->workspace($admin, 'business');
        $this->withSession(['active_workspace_id' => $other->id])->post('/ro/membri/invitations/'.$invitation->id.'/revoke')->assertNotFound();
        $this->withSession(['active_workspace_id' => $this->personal->id])->get('/ro/membri')->assertForbidden();
    }

    public function test_removal_requires_confirmation_and_old_accept_cannot_restore_access(): void
    {
        $invitation = $this->invite();
        $this->actingAs($this->recipient)->post('/ro/invitatii/'.$invitation->id)->assertRedirect();
        $this->actingAs($this->owner)->withSession(['active_workspace_id' => $this->business->id]);
        $this->delete('/ro/membri/'.$this->recipient->id)->assertSessionHasErrors('confirm');
        $this->delete('/ro/membri/'.$this->recipient->id, ['confirm' => 1])->assertRedirect();
        $this->actingAs($this->recipient)->get('/ro/documente')->assertForbidden();
        $this->post('/ro/invitatii/'.$invitation->id)->assertGone();
    }

    public function test_guest_link_only_remembers_invitation_and_never_accepts_it(): void
    {
        $invitation = $this->invite();
        $mail = $this->mail();
        auth()->logout();
        $this->get('/ro/invitatii/'.$invitation->id.'/'.$mail->token)->assertRedirect('/ro/autentificare')->assertSessionHas('pending_invitation', $invitation->id);
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/', $mail->actionUrl());
        $this->assertStringNotContainsString('token_hash', $mail->toMail(new AnonymousNotifiable)->render());
    }

    public function test_all_four_locales_have_incoming_routes_and_personal_spaces_cannot_invite(): void
    {
        $personal = $this->owner->workspaces()->where('type', 'personal')->first();
        $this->withSession(['active_workspace_id' => $personal->id]);
        $this->get('/ro/membri')->assertNotFound();
        $this->post('/ro/membri', ['email' => 'nobody@example.test', 'role' => 'member'])->assertForbidden();
        foreach (['ro/invitatii', 'en/invitations', 'hu/meghivasok', 'bg/pokani'] as $path) {
            $this->get('/'.$path)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
    }
}
