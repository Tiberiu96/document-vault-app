<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class WorkspaceUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::forceCreate(['owner_id' => $this->owner->id, 'type' => 'business', 'name' => 'Example business']);
        $this->workspace->members()->attach($this->owner->id, ['role' => 'owner']);
        $this->actingAs($this->owner)->withSession(['active_workspace_id' => $this->workspace->id]);
    }

    public function test_free_workspace_can_request_upgrade_once_without_changing_plan_or_seats(): void
    {
        $this->get('/ro/plan-si-consum')->assertOk()->assertSee('Solicita upgrade');
        $this->post('/ro/plan-si-consum', ['additional_member_seats' => 2, 'plan' => 'pro'])->assertRedirect();
        $this->post('/ro/plan-si-consum', ['additional_member_seats' => 9])->assertRedirect();
        $this->assertDatabaseCount('workspace_upgrade_requests', 1);
        $this->assertDatabaseHas('workspace_upgrade_requests', ['workspace_id' => $this->workspace->id, 'status' => 'pending', 'additional_member_seats' => 2]);
        $this->assertSame('free', $this->workspace->fresh()->plan_code);
        $this->assertSame(0, $this->workspace->fresh()->additional_member_seats);
        $this->get('/ro/plan-si-consum')->assertOk()->assertSee('Cerere trimisa');
    }

    public function test_business_admin_cannot_request_upgrade_on_behalf_of_owner(): void
    {
        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, ['role' => 'admin']);
        $this->actingAs($member)->get('/ro/plan-si-consum')->assertOk()->assertSee('Doar proprietarul');
        $this->post('/ro/plan-si-consum', ['additional_member_seats' => 3])->assertForbidden();
        $this->assertDatabaseCount('workspace_upgrade_requests', 0);
    }

    public function test_another_workspace_id_cannot_redirect_the_request(): void
    {
        $other = Workspace::forceCreate(['owner_id' => User::factory()->create()->id, 'type' => 'business', 'name' => 'Other business']);
        $this->post('/ro/plan-si-consum', ['workspace_id' => $other->id])->assertRedirect();
        $this->assertDatabaseHas('workspace_upgrade_requests', ['workspace_id' => $this->workspace->id]);
        $this->assertDatabaseMissing('workspace_upgrade_requests', ['workspace_id' => $other->id]);
        $this->withSession(['active_workspace_id' => $other->id])->post('/ro/plan-si-consum')->assertForbidden();
    }

    public function test_paid_business_only_requests_more_than_existing_additional_seats(): void
    {
        DB::table('platform_plan_limits')->where('plan', 'pro')->update(['enabled' => true]);
        $this->workspace->forceFill(['plan_code' => 'pro', 'additional_member_seats' => 2])->save();
        $this->get('/ro/plan-si-consum')->assertOk()->assertSee('Solicita locuri suplimentare');
        $this->post('/ro/plan-si-consum', ['additional_member_seats' => 2])->assertSessionHasErrors('additional_member_seats');
        $this->post('/ro/plan-si-consum', ['additional_member_seats' => 3])->assertRedirect();
        $this->assertSame(2, $this->workspace->fresh()->additional_member_seats);
    }

    public function test_personal_pro_has_no_fictitious_higher_plan(): void
    {
        DB::table('platform_plan_limits')->where('plan', 'pro')->update(['enabled' => true]);
        $this->workspace->forceFill(['type' => 'personal', 'plan_code' => 'pro'])->save();
        $this->get('/ro/plan-si-consum')->assertOk()->assertSee('Ai cel mai mare plan disponibil');
        $this->post('/ro/plan-si-consum')->assertSessionHasErrors('additional_member_seats');
        $this->assertDatabaseCount('workspace_upgrade_requests', 0);
    }

    public function test_admin_approval_waits_for_requested_capacity_and_can_reject_pending_requests(): void
    {
        $this->post('/ro/plan-si-consum', ['additional_member_seats' => 2])->assertRedirect();
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true, 'admin_mfa_secret' => (new Google2FA)->generateSecretKey(32), 'admin_mfa_confirmed_at' => now(), 'admin_mfa_version' => 1])->save();
        $this->actingAs($admin, 'admin')->withSession(['admin' => ['password_at' => now()->timestamp, 'password_fingerprint' => hash('sha256', $admin->password), 'last_seen' => now()->timestamp,
            'mfa' => ['user_id' => $admin->id, 'version' => 1, 'at' => now()->timestamp]]]);
        $this->get('/admin/spatii?upgrade=pending')->assertOk()->assertSee('Upgrade solicitat');
        DB::table('platform_plan_limits')->where('plan', 'pro')->update(['enabled' => true, 'extra_member_price_bani' => 1200]);
        $url = '/admin/spatii/'.$this->workspace->id.'/plan';
        $this->patch($url, ['plan' => 'pro', 'version' => 0, 'additional_member_seats' => 1, 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('workspace_upgrade_requests', ['workspace_id' => $this->workspace->id, 'status' => 'pending']);
        $this->patch($url, ['plan' => 'pro', 'version' => 1, 'additional_member_seats' => 2, 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('workspace_upgrade_requests', ['workspace_id' => $this->workspace->id, 'status' => 'approved', 'reviewed_by' => $admin->id]);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'plan.upgrade_approved']);
        $this->actingAs($this->owner, 'web')->post('/ro/plan-si-consum', ['additional_member_seats' => 3])->assertRedirect();
        $this->actingAs($admin, 'admin');
        $pending = DB::table('workspace_upgrade_requests')->where('status', 'pending')->firstOrFail();
        $this->post($url.'/cerere/refuza', ['request_id' => $pending->id])->assertSessionHasErrors('confirm');
        $this->post($url.'/cerere/refuza', ['request_id' => $pending->id, 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('workspace_upgrade_requests', ['id' => $pending->id, 'status' => 'rejected']);
        $this->assertSame(2, $this->workspace->fresh()->additional_member_seats);
    }
}
