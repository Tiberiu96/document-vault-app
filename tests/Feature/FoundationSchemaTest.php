<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_have_personal_and_multiple_business_workspaces(): void
    {
        $user = User::factory()->create();
        foreach (['personal', 'business', 'business'] as $type) {
            $workspace = Workspace::forceCreate(['owner_id' => $user->id, 'name' => 'Example', 'type' => $type]);
            $workspace->members()->attach($user, ['role' => 'owner']);
        }

        $this->assertCount(3, $user->workspaces);
        $this->assertDatabaseCount('memberships', 3);
    }

    public function test_database_rejects_a_second_personal_workspace_for_the_same_owner(): void
    {
        $user = User::factory()->create();
        $data = ['owner_id' => $user->id, 'name' => 'Example', 'type' => 'personal'];
        Workspace::forceCreate($data);

        $this->expectException(QueryException::class);
        Workspace::forceCreate($data);
    }

    public function test_database_rejects_duplicate_memberships(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::forceCreate(['owner_id' => $user->id, 'name' => 'Example', 'type' => 'business']);
        $workspace->members()->attach($user, ['role' => 'owner']);

        $this->expectException(QueryException::class);
        $workspace->members()->attach($user, ['role' => 'admin']);
    }

    public function test_company_belongs_to_one_workspace_and_keeps_structured_address(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::forceCreate(['owner_id' => $user->id, 'name' => 'Example', 'type' => 'business']);
        $company = $workspace->company()->create([
            'legal_name' => 'Example SRL', 'cui' => 'TEST-CUI',
            'registration_number' => 'TEST-REG',
            'registered_address' => ['city' => 'Cluj-Napoca', 'street' => 'Exemplu 1'],
        ]);

        $this->assertSame($workspace->id, $company->workspace->id);
        $this->assertSame('Cluj-Napoca', Company::sole()->registered_address['city']);
        $this->assertSame($company->id, $workspace->company->id);
    }

    public function test_owner_cannot_be_deleted_while_a_workspace_exists(): void
    {
        $user = User::factory()->create();
        Workspace::forceCreate(['owner_id' => $user->id, 'name' => 'Example', 'type' => 'personal']);

        $this->expectException(QueryException::class);
        $user->delete();
    }
}
