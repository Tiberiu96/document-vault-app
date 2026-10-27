<?php

namespace Tests\Feature;

use App\Contracts\Ocr\OcrProviderInterface;
use App\Jobs\Documents\ProcessDocument;
use App\Jobs\Documents\PurgeDocument;
use App\Models\Category;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\ProcessingUsage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\DocumentAiAnalysis;
use App\Services\Documents\DocumentLibrary;
use App\Services\Documents\DocumentProcessingService;
use App\Services\Ocr\OcrException;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class DocumentProcessingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Document $document;

    private string $text = 'FACTURA Nr. 123. Furnizor Example SRL. Servicii documente. Total 149.99 RON. Data emiterii: 01.01.2020. Plata prin banca.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['ai.provider' => 'ollama', 'ai.enabled' => true, 'ocr.enabled' => true, 'ai.ollama.context_length' => 8192]);
        $this->user = User::factory()->create();
        $this->workspace = new Workspace;
        $this->workspace->forceFill(['name' => 'Processing example', 'owner_id' => $this->user->id, 'type' => 'personal'])->save();
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);
        $this->document = app(DocumentLibrary::class)->upload($this->user, $this->workspace, UploadedFile::fake()->image('example.png'), ['title' => 'Manual title']);
        $this->actingAs($this->user)->withSession(['active_workspace_id' => $this->workspace->id]);
    }

    private function response(array $changes = []): array
    {
        return array_replace(['document_like' => true, 'readable' => true, 'confident' => true, 'category_key' => 'invoices', 'tag_keys' => ['to_review'], 'summary' => 'Factura pentru servicii documente.', 'supplier' => 'Example SRL', 'amount' => '149.99', 'currency' => 'RON', 'issued_on' => '2020-01-01', 'expires_on' => null], $changes);
    }

    private function readyText(?string $text = null): void
    {
        $this->document->forceFill(['ocr_status' => 'ready', 'ocr_text' => $text ?? $this->text])->save();
    }

    private function fakeAi(array $changes = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:11434/api/chat' => Http::response(['message' => ['content' => json_encode($this->response($changes))], 'done' => true, 'done_reason' => 'stop'])]);
    }

    private function execute(ProcessingRun $run, ?OcrProviderInterface $ocr = null): void
    {
        $ocr ??= Mockery::mock(OcrProviderInterface::class);
        (new ProcessDocument($run->id, $this->workspace->id, $run->kind))->handle(app(DocumentProcessingService::class), $ocr, app(DocumentAiAnalysis::class));
    }

    public function test_ocr_reservation_and_success_are_idempotent_and_text_is_encrypted(): void
    {
        $service = app(DocumentProcessingService::class);
        $run = $service->request($this->user, $this->document, 'ocr');
        $same = $service->request($this->user, $this->document, 'ocr');
        $this->assertSame($run->id, $same->id);
        Queue::assertPushedOn('ocr', ProcessDocument::class);
        Queue::assertPushed(ProcessDocument::class, 1);
        $this->assertSame(1, (int) ProcessingUsage::sole()->ocr_reserved);
        $ocr = Mockery::mock(OcrProviderInterface::class);
        $ocr->shouldReceive('extract')->once()->andReturn(['text' => $this->text, 'engine' => 'fake']);
        $this->execute($run, $ocr);
        $this->execute($run, $ocr);
        $this->assertSame('ready', $this->document->fresh()->ocr_status);
        $this->assertSame($this->text, $this->document->fresh()->ocr_text);
        $this->assertSame(0, (int) ProcessingUsage::sole()->ocr_reserved);
        $this->assertSame(1, (int) ProcessingUsage::sole()->ocr_used);
        $this->assertStringNotContainsString('FACTURA', DB::table('documents')->value('ocr_text'));
        $this->assertArrayNotHasKey('ocr_text', $this->document->fresh()->toArray());
    }

    public function test_permanent_failure_and_revocation_release_reservations_without_usage(): void
    {
        $service = app(DocumentProcessingService::class);
        $run = $service->request($this->user, $this->document, 'ocr');
        $ocr = Mockery::mock(OcrProviderInterface::class);
        $ocr->shouldReceive('extract')->once()->andThrow(new OcrException('OCR_PROCESS_FAILED'));
        $this->execute($run, $ocr);
        $service->fail($run->id, $this->workspace->id, 'DUPLICATE_FAILURE');
        $this->assertSame(0, (int) ProcessingUsage::sole()->ocr_reserved);
        $this->assertSame(0, (int) ProcessingUsage::sole()->ocr_used);
        $run = $service->request($this->user, $this->document, 'ocr');
        $this->workspace->members()->detach($this->user);
        $this->execute($run);
        $this->assertSame('ACCESS_REVOKED', $run->fresh()->error_code);
        $this->assertSame(0, (int) ProcessingUsage::sole()->ocr_reserved);
    }

    public function test_monthly_quota_and_pending_purge_do_not_leak_reservations(): void
    {
        config(['vault.plans.free.monthly_ocr_pages' => 0]);
        try {
            app(DocumentProcessingService::class)->request($this->user, $this->document, 'ocr');
            $this->fail('Expected quota rejection.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('processing_runs', 0);
        }
        config(['vault.plans.free.monthly_ocr_pages' => 50]);
        app(DocumentProcessingService::class)->request($this->user, $this->document, 'ocr');
        $this->document->delete();
        $this->document->forceFill(['purge_requested_at' => now()])->save();
        (new PurgeDocument($this->document->id, $this->workspace->id))->handle();
        $this->assertSame(0, (int) ProcessingUsage::sole()->ocr_reserved);
        $this->assertDatabaseCount('processing_runs', 0);
    }

    public function test_ai_is_optional_and_requires_text_and_workspace_permission(): void
    {
        $this->post('/ro/documente/'.$this->document->id.'/proceseaza/ai')->assertSessionHasErrors('processing');
        Http::assertNothingSent();
        $other = User::factory()->create();
        $this->actingAs($other)->get('/ro/documente/'.$this->document->id.'/stare-procesare')->assertForbidden();
        $this->assertDatabaseCount('processing_runs', 0);
    }

    public function test_low_quality_text_never_calls_ai_and_never_offers_autofill(): void
    {
        $this->readyText('??? ### ...');
        $run = app(DocumentProcessingService::class)->request($this->user, $this->document, 'ai');
        $this->execute($run);
        $this->assertSame('needs_review', $this->document->fresh()->ai_status);
        $this->assertSame([], $run->fresh()->result['fields']);
        $this->assertSame(0, (int) ProcessingUsage::sole()->ai_used);
        Http::assertNothingSent();
        $this->get('/ro/documente/'.$this->document->id)->assertOk()->assertSee('Necesita verificare manuala')->assertDontSee('Confirma si salveaza');
        $this->get('/ro/documente/'.$this->document->id.'/sugestii')->assertRedirect();
    }

    public function test_uncertain_non_document_and_expired_content_never_offers_autofill(): void
    {
        $this->readyText($this->text.' Data expirarii: 01.01.2021.');
        foreach ([['confident' => false], ['document_like' => false], ['readable' => false], ['expires_on' => '2021-01-01'], ['supplier' => 'Unseen supplier']] as $case) {
            $this->fakeAi($case);
            $result = app(DocumentAiAnalysis::class)->analyze($this->document->fresh(), 'ro');
            $this->assertTrue($result['review_required']);
            $this->assertSame([], $result['fields']);
        }
        $this->fakeAi();
        $result = app(DocumentAiAnalysis::class)->analyze($this->document->fresh(), 'ro');
        $this->assertFalse($result['review_required']); // Data emiterii veche este normala.
    }

    public function test_structured_suggestions_do_not_change_metadata_until_reviewed(): void
    {
        $this->readyText();
        $this->fakeAi();
        $run = app(DocumentProcessingService::class)->request($this->user, $this->document, 'ai');
        $this->execute($run);
        $this->assertSame('ready', $this->document->fresh()->ai_status);
        $this->assertNull($this->document->fresh()->summary);
        $this->assertSame('Manual title', $this->document->fresh()->title);
        $this->get('/ro/documente/'.$this->document->id.'/sugestii')->assertOk()->assertSee('Factura pentru servicii documente.')->assertSee('Confirma si salveaza');
        $this->assertNull($this->document->fresh()->summary);
        $category = Category::forWorkspace($this->workspace)->where('system_key', 'invoices')->sole();
        $this->patch('/ro/documente/'.$this->document->id, ['title' => 'Confirmed title', 'summary' => 'Summary corrected by user', 'category_id' => $category->id, 'ai_run_id' => $run->id, 'metadata_version' => 0])->assertRedirect();
        $this->assertSame('accepted', $this->document->fresh()->ai_status);
        $this->assertSame('Summary corrected by user', $this->document->fresh()->summary);
        $this->assertNotNull($run->fresh()->accepted_at);
        $this->assertSame(1, (int) ProcessingUsage::sole()->ai_used);
        $this->assertSame(0, (int) ProcessingUsage::sole()->ai_reserved);
    }

    public function test_duplicate_tags_and_copied_dates_are_normalized_only_against_source(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $this->readyText('Factura pentru servicii documente. Furnizor Example SRL. Total 149.99 RON. Emisa 27.10.2026. Expira 01.12.2026.');
        $this->fakeAi(['amount' => '149,99', 'tag_keys' => ['to_review', 'to_review'], 'issued_on' => '2710-20-26', 'expires_on' => '01.12.2026']);
        $result = app(DocumentAiAnalysis::class)->analyze($this->document->fresh(), 'ro');
        $this->assertFalse($result['review_required']);
        $this->assertSame(['to_review'], $result['fields']['tag_keys']);
        $this->assertSame('149.99', $result['fields']['amount']);
        $this->assertSame('2026-10-27', $result['fields']['issued_on']);
        $this->assertSame('2026-12-01', $result['fields']['expires_on']);
    }

    public function test_upload_option_is_checked_beside_file_and_can_be_declined(): void
    {
        $page = $this->get('/ro/documente/incarca')->assertOk();
        $page->assertSeeInOrder(['id="document-file"', 'name="analyze_with_ai" value="1" checked', 'Incarca si continua'], false);
        $this->assertFalse(str_contains($page->getContent(), 'name="title"'));
        $this->assertFalse(str_contains($page->getContent(), 'name="supplier"'));
        $this->post('/ro/documente', ['title' => 'Only OCR', 'file' => UploadedFile::fake()->image('only-ocr.png', 30, 40), 'analyze_with_ai' => '0'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertFalse(Document::where('title', 'Only OCR')->sole()->ai_requested);
    }

    public function test_file_can_be_uploaded_before_any_metadata_and_ai_review_is_next(): void
    {
        $this->post('/ro/documente', ['file' => UploadedFile::fake()->image('invoice-first.png', 45, 55), 'analyze_with_ai' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $record = Document::where('title', 'invoice-first')->sole();
        $this->assertTrue($record->ai_requested);
        $this->assertNull($record->supplier);
        $this->assertNull($record->summary);
        $this->get('/ro/documente/'.$record->id.'?review=1')->assertOk();
        $record->forceFill(['ai_status' => 'needs_review', 'ocr_status' => 'ready'])->save();
        $this->get('/ro/documente/'.$record->id.'?review=1')->assertRedirect(route('documents.edit', ['document' => $record->id]));
        $this->get('/ro/documente/'.$record->id.'/editeaza')->assertOk()->assertSee('Necesita verificare manuala');
        $this->patch('/ro/documente/'.$record->id, ['summary' => 'Missing name'])->assertSessionHasErrors('title');
    }

    public function test_ocr_chains_ai_only_with_opt_in_and_preserves_request_locale(): void
    {
        $this->document->forceFill(['ai_requested' => true])->save();
        app()->setLocale('hu');
        $service = app(DocumentProcessingService::class);
        $run = $service->request($this->user, $this->document, 'ocr');
        app()->setLocale('ro');
        $ocr = Mockery::mock(OcrProviderInterface::class);
        $ocr->shouldReceive('extract')->once()->andReturn(['text' => $this->text, 'engine' => 'fake']);
        $this->execute($run, $ocr);
        $ai = ProcessingRun::where('kind', 'ai')->sole();
        $this->assertSame('hu', $ai->locale);
        $this->assertSame('queued', $this->document->fresh()->ai_status);
        $this->assertSame('ro', app()->getLocale());
        Queue::assertPushedOn('ai', ProcessDocument::class);
        $service->complete($run->id, $this->workspace->id, ['text' => $this->text, 'engine' => 'fake']);
        $this->assertSame(1, ProcessingRun::where('kind', 'ai')->count());
    }

    public function test_queued_ai_keeps_original_provider_after_configuration_changes(): void
    {
        $this->readyText();
        $this->fakeAi();
        $run = app(DocumentProcessingService::class)->request($this->user, $this->document, 'ai');
        $this->assertSame('ollama', $run->provider);
        config(['ai.provider' => 'groq']);
        $this->execute($run);
        $this->assertSame('ready', $this->document->fresh()->ai_status);
        $this->assertSame('groq', config('ai.provider'));
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:11434/api/chat');
    }

    public function test_status_exposes_progress_but_no_private_text_and_requires_current_suggestions(): void
    {
        $this->readyText();
        $this->fakeAi();
        $run = app(DocumentProcessingService::class)->request($this->user, $this->document, 'ai');
        $this->get('/ro/documente/'.$this->document->id.'/stare-procesare')->assertOk()
            ->assertJsonPath('ai_status', 'queued')->assertJsonPath('ready_for_review', false)->assertDontSee('FACTURA');
        $this->execute($run);
        $this->get('/ro/documente/'.$this->document->id.'/stare-procesare')->assertOk()->assertJsonPath('ready_for_review', true);
        $this->document->refresh()->increment('metadata_version');
        $this->get('/ro/documente/'.$this->document->id.'/stare-procesare')->assertOk()->assertJsonPath('ready_for_review', false);
    }

    public function test_stale_suggestions_and_invalid_category_are_rejected(): void
    {
        $this->readyText();
        $this->fakeAi();
        $run = app(DocumentProcessingService::class)->request($this->user, $this->document, 'ai');
        $this->execute($run);
        $this->patch('/ro/documente/'.$this->document->id, ['title' => 'Manually edited'])->assertRedirect();
        $this->get('/ro/documente/'.$this->document->id.'/sugestii')->assertSessionHasErrors('processing');
        $this->patch('/ro/documente/'.$this->document->id, ['title' => 'Blocked old result', 'ai_run_id' => $run->id, 'metadata_version' => 0])->assertSessionHasErrors('processing');
        $this->assertSame('Manually edited', $this->document->fresh()->title);
        $this->fakeAi(['category_key' => 'invented']);
        $this->expectException(AiProviderException::class);
        app(DocumentAiAnalysis::class)->analyze($this->document->fresh(), 'ro');
    }
}
