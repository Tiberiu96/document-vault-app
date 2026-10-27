<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceSwitchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LanguageSeeder::class);
    }

    private function workspace(User $owner, string $type = 'business'): Workspace
    {
        $workspace = Workspace::forceCreate(['owner_id' => $owner->id, 'type' => $type, 'name' => 'Example workspace']);
        $workspace->members()->attach($owner, ['role' => 'owner']);

        return $workspace;
    }

    public function test_user_can_switch_between_authorized_workspaces(): void
    {
        $user = User::factory()->create();
        $personal = $this->workspace($user, 'personal');
        $business = $this->workspace($user);
        $this->actingAs($user)->withSession(['active_workspace_id' => $personal->id])
            ->post('/en/workspaces/switch', ['workspace_id' => $business->id])->assertRedirect('/en/dashboard')->assertSessionHas('active_workspace_id', $business->id);
    }

    public function test_non_member_cannot_switch_to_another_workspace(): void
    {
        $user = User::factory()->create();
        $own = $this->workspace($user);
        $foreign = $this->workspace(User::factory()->create());
        $this->actingAs($user)->withSession(['active_workspace_id' => $own->id])
            ->post('/ro/spatii/selecteaza', ['workspace_id' => $foreign->id])->assertNotFound()->assertSessionHas('active_workspace_id', $own->id);
    }

    public function test_personal_workspace_remains_private_even_with_an_invalid_membership(): void
    {
        $user = User::factory()->create();
        $private = $this->workspace(User::factory()->create(), 'personal');
        $private->members()->attach($user, ['role' => 'admin']);
        $this->actingAs($user)->post('/ro/spatii/selecteaza', ['workspace_id' => $private->id])->assertForbidden();
    }

    public function test_stale_active_workspace_is_cleared_after_access_is_removed(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user);
        $workspace->members()->detach($user);
        $this->actingAs($user)->withSession(['active_workspace_id' => $workspace->id])
            ->get('/ro/panou')->assertOk()->assertSessionMissing('active_workspace_id')->assertDontSee('Example workspace');
    }

    public function test_switching_requires_authentication_and_rejects_invalid_ids(): void
    {
        $this->post('/ro/spatii/selecteaza', ['workspace_id' => 1])->assertRedirect('/ro/autentificare');
        $this->actingAs(User::factory()->create())->post('/ro/spatii/selecteaza', ['workspace_id' => ['invalid']])->assertSessionHasErrors('workspace_id');
    }
}
