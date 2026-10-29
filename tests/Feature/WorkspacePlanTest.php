<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\ProcessingUsage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Documents\DocumentProcessingService;
use App\Services\Documents\ProcessingQuotaExceeded;
use App\Services\PlanLimitsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\PdfFixture;
use Tests\TestCase;

class WorkspacePlanTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        Storage::fake('local');
        config(['ocr.enabled' => true, 'ai.enabled' => true]);
        $this->owner = User::factory()->create();
        $this->workspace = $this->space($this->owner);
        $this->actingAs($this->owner)->withSession(['active_workspace_id' => $this->workspace->id]);
    }

    private function space(User $owner): Workspace
    {
        $workspace = Workspace::forceCreate(['owner_id' => $owner->id, 'type' => 'business', 'name' => 'Example company']);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        return $workspace->fresh();
    }

    private function pro(array $changes = []): void
    {
        DB::table('platform_plan_limits')->where('plan', 'pro')->update(array_replace(['enabled' => true, 'max_pages' => 15, 'monthly_ocr_pages' => 100, 'monthly_ai_calls' => 20, 'storage_bytes' => 209715200], $changes));
        $this->workspace->forceFill(['plan_code' => 'pro'])->save();
    }

    private function document(User $user, int $pages = 2, ?Workspace $workspace = null): Document
    {
        return Document::forceCreate(['workspace_id' => ($workspace ?? $this->workspace)->id, 'uploaded_by' => $user->id,
            'title' => 'Example document', 'original_name' => 'example.pdf', 'storage_path' => 'test/original.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 100,
            'page_count' => $pages, 'sha256' => hash('sha256', random_bytes(8)), 'status' => 'stored', 'ocr_status' => 'ready', 'ocr_text' => 'Example invoice text for testing.']);
    }

    public function test_pro_is_disabled_initially_and_expiry_or_disabling_falls_back_to_free(): void
    {
        $limits = app(PlanLimitsService::class);
        $this->workspace->forceFill(['plan_code' => 'pro'])->save();
        $this->assertSame('free', $limits->resolve($this->workspace)['code']);
        $this->pro();
        $this->assertSame(15, $limits->forWorkspace($this->workspace)['max_pages']);
        $this->workspace->forceFill(['plan_expires_at' => now()->addMinute()])->save();
        $this->assertSame('pro', $limits->resolve($this->workspace)['code']);
        $this->travel(61)->seconds();
        $this->assertSame('free', $limits->resolve($this->workspace)['code']);
        $this->workspace->forceFill(['plan_expires_at' => null])->save();
        DB::table('platform_plan_limits')->where('plan', 'pro')->update(['enabled' => false]);
        $this->assertSame('free', $limits->resolve($this->workspace)['code']);
    }

    public function test_pdf_and_scan_uploads_obey_the_workspace_plan_and_ignore_client_upgrade_fields(): void
    {
        config(['vault.plans.free.max_pages' => 2]);
        $file = fn () => UploadedFile::fake()->createWithContent('example.pdf', PdfFixture::make(3));
        $this->post('/ro/documente', ['file' => $file(), 'plan_code' => 'pro'])->assertSessionHasErrors('file');
        $this->assertSame(0, Document::count());
        $this->assertSame('free', $this->workspace->fresh()->plan_code);
        $this->pro();
        $this->post('/ro/documente', ['file' => $file()])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(3, Document::sole()->page_count);
        $pages = fn () => [UploadedFile::fake()->image('a.jpg', 300, 400), UploadedFile::fake()->image('b.jpg', 300, 400), UploadedFile::fake()->image('c.jpg', 300, 400)];
        $this->workspace->forceFill(['plan_code' => 'free'])->save();
        $this->post('/ro/documente/scaneaza', ['pages' => $pages()])->assertSessionHasErrors('pages');
        $this->workspace->forceFill(['plan_code' => 'pro'])->save();
        $this->post('/ro/documente/scaneaza', ['pages' => $pages()])->assertCreated()->assertSessionHasNoErrors();
        $this->assertSame(2, Document::count());
    }

    public function test_members_share_ai_reservations_and_settlement_does_not_double_count(): void
    {
        $this->pro(['monthly_ai_calls' => 1]);
        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, ['role' => 'member']);
        $service = app(DocumentProcessingService::class);
        $run = $service->request($this->owner, $this->document($this->owner), 'ai');
        $metric = app(PlanLimitsService::class)->usage($this->workspace)['metrics']['ai'];
        $this->assertSame(1, $metric['reserved']);
        $this->assertSame(0, $metric['remaining']);
        try {
            $service->request($member, $this->document($member), 'ai');
            $this->fail('Shared reservation must exhaust the quota.');
        } catch (ProcessingQuotaExceeded) {
            $this->assertSame(1, ProcessingUsage::sole()->ai_reserved);
        }
        $service->complete($run->id, $this->workspace->id, ['review_required' => false]);
        $service->complete($run->id, $this->workspace->id, ['review_required' => false]);
        $usage = ProcessingUsage::sole();
        $this->assertSame(1, $usage->ai_used);
        $this->assertSame(0, $usage->ai_reserved);
        $other = $this->space($member);
        $this->assertSame(0, app(PlanLimitsService::class)->usage($other)['metrics']['ai']['used']);
        $this->travelTo(now('UTC')->addMonth()->startOfMonth());
        $this->assertSame(1, app(PlanLimitsService::class)->usage($this->workspace)['metrics']['ai']['remaining']);
        $this->assertSame(1, ProcessingUsage::sole()->ai_used);
    }

    public function test_downgrade_keeps_usage_and_storage_and_blocks_new_reservations(): void
    {
        $this->pro();
        $this->workspace->forceFill(['storage_used_bytes' => 150000000])->save();
        ProcessingUsage::forceCreate(['workspace_id' => $this->workspace->id, 'period' => now('UTC')->startOfMonth()->toDateString(), 'ai_used' => 20]);
        $this->workspace->forceFill(['plan_code' => 'free'])->save();
        $limits = app(PlanLimitsService::class);
        $snapshot = $limits->usage($this->workspace);
        $this->assertTrue($snapshot['metrics']['storage']['exceeded']);
        $this->assertSame(0, $snapshot['metrics']['storage']['remaining']);
        $this->assertSame(20, $snapshot['metrics']['ai']['used']);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $limits->reserveStorage(Workspace::lockForUpdate()->findOrFail($this->workspace->id), 1));
    }

    public function test_storage_reservations_use_latest_locked_workspace_balance(): void
    {
        config(['vault.plans.free.storage_bytes' => 100]);
        $limits = app(PlanLimitsService::class);
        DB::transaction(fn () => $limits->reserveStorage(Workspace::lockForUpdate()->findOrFail($this->workspace->id), 70));
        try {
            DB::transaction(fn () => $limits->reserveStorage(Workspace::lockForUpdate()->findOrFail($this->workspace->id), 40));
            $this->fail('Second reservation must not exceed shared capacity.');
        } catch (ValidationException) {
            $this->assertSame(70, $this->workspace->fresh()->storage_used_bytes);
        }
    }

    public function test_usage_is_localized_and_cannot_be_read_through_another_workspace_id(): void
    {
        $other = $this->space(User::factory()->create());
        foreach (['ro/plan-si-consum', 'en/plan-and-usage', 'hu/csomag-es-hasznalat', 'bg/plan-i-potreblenie'] as $url) {
            $this->get('/'.$url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        $this->withSession(['active_workspace_id' => $other->id])->get('/ro/plan-si-consum')->assertForbidden();
        $this->patch('/admin/spatii/'.$this->workspace->id.'/plan', ['plan' => 'pro', 'version' => 0, 'confirm' => 1])->assertRedirect('/admin/autentificare');
    }

    public function test_admin_assignment_requires_mfa_confirmation_enabled_plan_and_fresh_version(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true, 'admin_mfa_secret' => (new Google2FA)->generateSecretKey(32), 'admin_mfa_confirmed_at' => now(), 'admin_mfa_version' => 1])->save();
        $this->actingAs($admin, 'admin')->withSession(['admin' => ['password_at' => now()->timestamp, 'password_fingerprint' => hash('sha256', $admin->password), 'last_seen' => now()->timestamp,
            'mfa' => ['user_id' => $admin->id, 'version' => 1, 'at' => now()->timestamp]]]);
        $url = '/admin/spatii/'.$this->workspace->id.'/plan';
        $this->get($url)->assertOk();
        $this->patch($url, ['plan' => 'pro', 'version' => 0])->assertSessionHasErrors('confirm');
        $payload = ['plan' => 'pro', 'version' => 0, 'confirm' => 1, 'expires_on' => now('UTC')->addDays(7)->toDateString()];
        $this->patch($url, $payload)->assertSessionHasErrors('plan');
        DB::table('platform_plan_limits')->where('plan', 'pro')->update(['enabled' => true]);
        $this->patch($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame('pro', $this->workspace->fresh()->plan_code);
        $this->assertNotNull($this->workspace->fresh()->plan_expires_at);
        $this->patch($url, $payload)->assertSessionHasErrors('version');
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'plan.assigned_pro', 'target_id' => (string) $this->workspace->id]);
        $this->withSession(['admin.mfa' => []])->patch($url, ['plan' => 'free', 'version' => 1, 'confirm' => 1])->assertRedirect('/admin/mfa/verificare');
    }
}
