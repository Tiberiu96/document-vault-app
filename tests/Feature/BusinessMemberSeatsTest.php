<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\PlanLimitsService;
use App\Services\WorkspaceInvitations;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class BusinessMemberSeatsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::forceCreate(['name' => 'Test business', 'type' => 'business', 'owner_id' => $this->owner->id]);
        $this->workspace->members()->attach($this->owner->id, ['role' => 'owner']);
        $this->actingAs($this->owner)->withSession(['active_workspace_id' => $this->workspace->id]);
    }

    private function invite(string $email): WorkspaceInvitation
    {
        return app(WorkspaceInvitations::class)->create($this->owner, $this->workspace, $email, 'member');
    }

    private function pro(int $seats): void
    {
        DB::table('platform_plan_limits')->where('plan', 'pro')->update(['enabled' => true, 'extra_member_price_bani' => 1200]);
        $this->workspace->forceFill(['plan_code' => 'pro', 'additional_member_seats' => $seats])->save();
    }

    public function test_owner_and_pending_invitations_use_the_same_five_seats(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->invite('member'.$i.'@example.test');
        }
        $seats = app(PlanLimitsService::class)->memberSeats($this->workspace);
        $this->assertSame(5, $seats['limit']);
        $this->assertSame(1, $seats['members']);
        $this->assertSame(4, $seats['pending']);
        $this->post('/ro/membri', ['email' => 'overflow@example.test', 'role' => 'member', 'additional_member_seats' => 999])->assertSessionHasErrors('email');
        $this->assertSame(4, $this->workspace->invitations()->count());
        $this->assertSame(0, $this->workspace->fresh()->additional_member_seats);
    }

    public function test_acceptance_converts_reservation_without_double_counting_and_is_idempotent(): void
    {
        $recipient = User::factory()->create();
        $invitation = $this->invite($recipient->email);
        for ($i = 0; $i < 3; $i++) {
            $this->invite('reserved'.$i.'@example.test');
        }
        $service = app(WorkspaceInvitations::class);
        $service->accept($recipient, $invitation);
        $service->accept($recipient, $invitation->fresh());
        $seats = app(PlanLimitsService::class)->memberSeats($this->workspace);
        $this->assertSame(2, $seats['members']);
        $this->assertSame(3, $seats['pending']);
        $this->assertSame(0, $seats['remaining']);
    }

    public function test_expiry_or_revocation_releases_a_seat_and_resend_cannot_overbook(): void
    {
        $expired = $this->invite('expired@example.test');
        $expired->forceFill(['expires_at' => now()->subSecond(), 'last_sent_at' => now()->subMinutes(2)])->save();
        for ($i = 0; $i < 4; $i++) {
            $this->invite('reserved'.$i.'@example.test');
        }
        try {
            app(WorkspaceInvitations::class)->resend($this->owner, $expired);
            $this->fail('Resending an expired invitation must reserve a free seat.');
        } catch (ValidationException) {
            $this->assertTrue($expired->fresh()->expires_at->isPast());
        }
        app(WorkspaceInvitations::class)->revoke($this->owner, $this->workspace->invitations()->where('email', 'reserved0@example.test')->firstOrFail());
        app(WorkspaceInvitations::class)->resend($this->owner, $expired);
        $this->assertSame(0, app(PlanLimitsService::class)->memberSeats($this->workspace)['remaining']);
    }

    public function test_expiry_removes_extra_capacity_without_removing_members_and_blocks_acceptance(): void
    {
        $this->pro(1);
        $recipient = User::factory()->create();
        $invitation = $this->invite($recipient->email);
        for ($i = 0; $i < 4; $i++) {
            $this->workspace->members()->attach(User::factory()->create()->id, ['role' => 'member']);
        }
        $this->workspace->forceFill(['plan_expires_at' => now()->subSecond()])->save();
        try {
            app(WorkspaceInvitations::class)->accept($recipient, $invitation);
            $this->fail('Expired additional seats must not grant membership.');
        } catch (ValidationException) {
            $this->assertSame(5, $this->workspace->members()->count());
            $this->assertFalse($this->workspace->members()->whereKey($recipient->id)->exists());
            $this->assertNull($invitation->fresh()->accepted_at);
        }
    }

    public function test_additional_capacity_is_limited_to_its_business_workspace(): void
    {
        $this->pro(2);
        $this->assertSame(7, app(PlanLimitsService::class)->memberSeats($this->workspace)['limit']);
        $other = Workspace::forceCreate(['name' => 'Other business', 'type' => 'business', 'owner_id' => $this->owner->id]);
        $this->assertSame(5, app(PlanLimitsService::class)->memberSeats($other)['limit']);
        $this->workspace->forceFill(['type' => 'personal'])->save();
        $this->assertSame(1, app(PlanLimitsService::class)->memberSeats($this->workspace)['limit']);
    }

    public function test_admin_requires_price_confirmation_and_fresh_version_for_additional_seats(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true, 'admin_mfa_secret' => (new Google2FA)->generateSecretKey(32), 'admin_mfa_confirmed_at' => now(), 'admin_mfa_version' => 1])->save();
        $this->actingAs($admin, 'admin')->withSession(['admin' => ['password_at' => now()->timestamp, 'password_fingerprint' => hash('sha256', $admin->password), 'last_seen' => now()->timestamp,
            'mfa' => ['user_id' => $admin->id, 'version' => 1, 'at' => now()->timestamp]]]);
        DB::table('platform_plan_limits')->where('plan', 'pro')->update(['enabled' => true]);
        $url = '/admin/spatii/'.$this->workspace->id.'/plan';
        $payload = ['plan' => 'pro', 'version' => 0, 'additional_member_seats' => 2, 'confirm' => 1];
        $this->patch($url, $payload)->assertSessionHasErrors('additional_member_seats');
        DB::table('platform_plan_limits')->where('plan', 'pro')->update(['extra_member_price_bani' => 1200]);
        $withoutConfirmation = $payload;
        unset($withoutConfirmation['confirm']);
        $this->patch($url, $withoutConfirmation)->assertSessionHasErrors('confirm');
        $this->patch($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame(7, app(PlanLimitsService::class)->memberSeats($this->workspace->fresh())['limit']);
        $this->patch($url, $payload)->assertSessionHasErrors('version');
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'plan.member_seats_updated', 'target_id' => (string) $this->workspace->id]);
    }
}
