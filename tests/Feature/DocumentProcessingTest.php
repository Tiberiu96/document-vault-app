<?php

namespace Tests\Feature;

use App\Contracts\Ocr\OcrProviderInterface;
use App\Jobs\Documents\ExpireDocumentDraft;
use App\Jobs\Documents\GenerateDocumentPreview;
use App\Jobs\Documents\ProcessDocument;
use App\Jobs\Documents\PurgeDocument;
use App\Jobs\Documents\ReleaseDraftSource;
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
use App\Services\Documents\DraftSource;
use App\Services\Ocr\OcrException;
use App\Services\Ocr\TextQuality;
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

    public function test_uncertain_or_non_document_content_never_offers_autofill(): void
    {
        $this->readyText($this->text.' Data expirarii: 01.01.2021.');
        foreach ([['confident' => false], ['document_like' => false], ['readable' => false], ['supplier' => 'Unseen supplier']] as $case) {
            $this->fakeAi($case);
            $result = app(DocumentAiAnalysis::class)->analyze($this->document->fresh(), 'ro');
            $this->assertTrue($result['review_required']);
            $this->assertSame([], $result['fields']);
        }
        $this->fakeAi();
        $result = app(DocumentAiAnalysis::class)->analyze($this->document->fresh(), 'ro');
        $this->assertFalse($result['review_required']); // Data emiterii veche este normala.
        $this->fakeAi(['expires_on' => '2021-01-01']);
        $expired = app(DocumentAiAnalysis::class)->analyze($this->document->fresh(), 'ro');
        $this->assertFalse($expired['review_required']);
        $this->assertContains('expired', $expired['warnings']);
        $this->assertSame('2021-01-01', $expired['fields']['expires_on']);
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

    public function test_invoice_without_expiry_or_status_evidence_keeps_optional_suggestions_empty(): void
    {
        $this->readyText($this->text.' Data scadentei: 01.01.2020.');
        $this->fakeAi(['tag_keys' => []]);
        $result = app(DocumentAiAnalysis::class)->analyze($this->document->fresh(), 'ro');
        $this->assertFalse($result['review_required']);
        $this->assertSame([], $result['fields']['tag_keys']);
        $this->assertNull($result['fields']['expires_on']);
        Http::assertSent(function ($request) {
            $catalog = json_decode($request['messages'][1]['content'], true)['tag_catalog'];

            return str_contains($catalog['paid'], 'payment method')
                && str_contains($request['messages'][0]['content'], 'payment due date');
        });
    }

    public function test_insurance_policy_uses_explicit_issue_expiry_and_premium_with_mocked_provider(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00', 'UTC'));
        $this->readyText('CONTRACT DE ASIGURARE RCA. Example SRL. Contract emis in data de 13/10/2026. Valabilitate de la 17/10/2026 pana la 16/10/2027. Prima totala 1250.50 lei. Limita despagubire 3000000 LEI.');
        $this->fakeAi(['category_key' => 'insurance', 'tag_keys' => [], 'summary' => 'Polita RCA.', 'amount' => '1250.50', 'issued_on' => '13/10/2026', 'expires_on' => '16/10/2027']);
        $result = app(DocumentAiAnalysis::class)->analyze($this->document->fresh(), 'ro');
        $this->assertFalse($result['review_required']);
        $this->assertSame('2026-10-13', $result['fields']['issued_on']);
        $this->assertSame('2027-10-16', $result['fields']['expires_on']);
        $this->assertSame('1250.50', $result['fields']['amount']);
        Http::assertSent(fn ($request) => str_contains($request['messages'][0]['content'], 'total insurance premium'));
    }

    public function test_invalid_ai_field_diagnostic_contains_only_application_field_name(): void
    {
        $this->readyText();
        $this->fakeAi(['summary' => str_repeat('private-response ', 30)]);
        try {
            app(DocumentAiAnalysis::class)->analyze($this->document->fresh(), 'ro');
            $this->fail('Expected validation rejection');
        } catch (AiProviderException $error) {
            $this->assertSame('AI_INVALID_SUMMARY', $error->reason);
            $this->assertStringNotContainsString('private-response', $error->getMessage());
        }
    }

    public function test_only_short_summary_is_editable_and_legacy_description_is_preserved(): void
    {
        $this->document->forceFill(['description' => 'Existing private note'])->save();
        $this->get('/ro/documente/'.$this->document->id.'/editeaza')
            ->assertOk()->assertSee('name="summary"', false)->assertDontSee('name="description"', false);
        $this->patch('/ro/documente/'.$this->document->id, ['title' => 'Updated', 'summary' => 'Short summary', 'metadata_version' => 0])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Short summary', $this->document->fresh()->summary);
        $this->assertSame('Existing private note', $this->document->fresh()->description);
    }

    public function test_review_uses_compact_preview_and_red_cancel_instead_of_routine_alerts(): void
    {
        $this->readyText($this->text.' Expira 01.01.2021.');
        $this->fakeAi(['expires_on' => '2021-01-01']);
        $run = app(DocumentProcessingService::class)->request($this->user, $this->document->fresh(), 'ai');
        $this->execute($run);
        $page = $this->get('/ro/documente/'.$this->document->id.'/sugestii')->assertOk();
        $page->assertSee('review-source')->assertSee('button button-danger', false)
            ->assertDontSee('class="security-notice"', false)->assertDontSee(__('processing.draft_notice'))
            ->assertSee('id="expiry-warning"', false)->assertSee(__('processing.expired_short'));
        $this->assertLessThan(strpos($page->getContent(), 'id="expiry-warning"'), strpos($page->getContent(), 'id="expires_on"'));
        $this->document->forceFill(['status' => 'draft'])->save();
        $this->get('/ro/documente/'.$this->document->id.'/editeaza')->assertOk()
            ->assertSee('data-retained-source', false)->assertSee('data-local-pdf', false)
            ->assertSee('form="discard-upload" formnovalidate class="button button-danger"', false)
            ->assertDontSee(__('processing.source_retained'));
    }

    public function test_upload_option_is_checked_beside_file_and_can_be_declined(): void
    {
        $page = $this->get('/ro/documente/incarca')->assertOk();
        $page->assertSee('Selecteaza fisierul')->assertSee('Ajutor incarcare');
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

    public function test_upload_remains_private_draft_until_explicit_confirmation(): void
    {
        $this->post('/ro/documente', ['file' => UploadedFile::fake()->image('private-draft.png', 55, 66), 'analyze_with_ai' => '0'])->assertSessionHasNoErrors();
        $draft = Document::where('title', 'private-draft')->sole();
        $this->assertSame('draft', $draft->status);
        $this->get('/ro/documente')->assertOk()->assertDontSee('private-draft');
        $this->get('/ro/panou')->assertViewHas('documentCount', 1);
        Queue::assertPushedOn('low', ExpireDocumentDraft::class);
        $this->get('/ro/documente/'.$draft->id.'/editeaza')->assertOk()->assertSee('Confirma si salveaza')->assertSee('form="discard-upload"', false);
        $this->workspace->forceFill(['type' => 'business'])->save();
        $admin = User::factory()->create();
        $this->workspace->members()->attach($admin->id, ['role' => 'admin']);
        $this->actingAs($admin)->get('/ro/documente/'.$draft->id)->assertForbidden();
        $this->actingAs($this->user)->patch('/ro/documente/'.$draft->id, ['title' => 'Confirmed document', 'metadata_version' => 0, 'file' => UploadedFile::fake()->image('private-draft.png', 55, 66)])->assertSessionHasNoErrors();
        $this->assertSame('stored', $draft->fresh()->status);
        $this->get('/ro/documente')->assertSee('Confirmed document');
        $this->delete('/ro/documente/'.$draft->id.'/renunta-incarcare')->assertStatus(409);
        (new ExpireDocumentDraft($draft->id, $this->workspace->id))->handle(app(DocumentLibrary::class));
        $this->assertNotNull($draft->fresh());
    }

    public function test_cancel_draft_removes_files_releases_reservations_and_allows_same_upload(): void
    {
        $before = $this->workspace->fresh()->storage_used_bytes;
        $this->post('/ro/documente', ['file' => UploadedFile::fake()->image('cancel-me.png', 66, 77), 'analyze_with_ai' => '1'])->assertSessionHasNoErrors();
        $draft = Document::where('title', 'cancel-me')->sole();
        $runId = $draft->ocr_run_id;
        $this->assertTrue(Storage::disk('local')->exists($draft->storage_path));
        $this->delete('/ro/documente/'.$draft->id.'/renunta-incarcare')->assertRedirect();
        $this->assertNull(Document::withTrashed()->find($draft->id));
        $this->assertFalse(Storage::disk('local')->exists($draft->storage_path));
        $this->assertSame($before, $this->workspace->fresh()->storage_used_bytes);
        $this->assertSame(0, (int) ProcessingUsage::sole()->ocr_reserved);
        app(DocumentProcessingService::class)->complete($runId, $this->workspace->id, ['text' => $this->text, 'engine' => 'fake']);
        $this->assertNull(Document::withTrashed()->find($draft->id));
        $this->post('/ro/documente', ['file' => UploadedFile::fake()->image('cancel-me.png', 66, 77), 'analyze_with_ai' => '0'])->assertSessionHasNoErrors();
    }

    public function test_cancelling_running_ai_does_not_refund_a_potentially_billable_attempt(): void
    {
        $this->document->forceFill(['status' => 'draft'])->save();
        $this->readyText();
        $service = app(DocumentProcessingService::class);
        $run = $service->request($this->user, $this->document, 'ai');
        $service->claim($run->id, $this->workspace->id, 1);
        app(DocumentLibrary::class)->discardDraft($this->document);
        $this->assertSame(1, (int) ProcessingUsage::sole()->ai_used);
        $this->assertSame(0, (int) ProcessingUsage::sole()->ai_reserved);
        $this->assertNull(Document::withTrashed()->find($this->document->id));
        Http::assertNothingSent();
    }

    public function test_expired_abandoned_draft_is_cleaned_without_touching_confirmed_documents(): void
    {
        $draft = app(DocumentLibrary::class)->upload($this->user, $this->workspace, UploadedFile::fake()->image('expired-draft.png', 44, 99), ['title' => 'Abandoned upload'], draft: true);
        $job = new ExpireDocumentDraft($draft->id, $this->workspace->id);
        $job->handle(app(DocumentLibrary::class));
        $this->assertNotNull($draft->fresh());
        $draft->forceFill(['created_at' => now()->subHours(25)])->save();
        $job->handle(app(DocumentLibrary::class));
        $this->assertNull(Document::withTrashed()->find($draft->id));
        $this->assertNotNull($this->document->fresh());
    }

    public function test_completed_ai_cannot_be_restarted_or_consume_additional_quota(): void
    {
        $this->readyText();
        $this->fakeAi();
        $service = app(DocumentProcessingService::class);
        $run = $service->request($this->user, $this->document, 'ai');
        $this->execute($run);
        foreach (['ready', 'accepted', 'rejected', 'needs_review'] as $status) {
            $this->document->refresh()->forceFill(['ai_status' => $status])->save();
            $this->assertSame($run->id, $service->request($this->user, $this->document, 'ai')->id);
            $this->get('/ro/documente/'.$this->document->id)->assertOk()->assertDontSee('Analizeaza cu AI');
        }
        $this->post('/ro/documente/'.$this->document->id.'/proceseaza/ai')->assertRedirect();
        $this->assertSame(1, ProcessingRun::where('kind', 'ai')->count());
        $this->assertSame(1, (int) ProcessingUsage::sole()->ai_used);
        $this->assertSame(0, (int) ProcessingUsage::sole()->ai_reserved);
        Http::assertSentCount(1);
    }

    public function test_scanner_creates_private_multipage_draft_and_rejects_excess_pages(): void
    {
        $this->get('/ro/documente/scaneaza')->assertOk()->assertSee('Continua')->assertSee('data-crop-corner="tl"', false)->assertDontSee('type="range"', false);
        $response = $this->postJson('/ro/documente/scaneaza', ['pages' => [UploadedFile::fake()->image('one.jpg', 150, 210), UploadedFile::fake()->image('two.png', 210, 150)], 'analyze_with_ai' => '0', 'workspace_id' => 999, 'status' => 'stored'])
            ->assertCreated()->assertJsonStructure(['redirect']);
        $record = Document::where('mime_type', 'application/pdf')->sole();
        $this->assertSame('draft', $record->status);
        $this->assertSame(2, $record->page_count);
        $this->assertSame($this->workspace->id, $record->workspace_id);
        $this->assertTrue(Storage::disk('local')->exists($record->storage_path));
        $this->assertFalse($record->ai_requested);
        $pages = array_map(fn ($i) => UploadedFile::fake()->image('page-'.$i.'.jpg', 10, 15), range(1, 11));
        $this->postJson('/ro/documente/scaneaza', ['pages' => $pages])->assertUnprocessable()->assertJsonValidationErrors('pages');
        $this->postJson('/ro/documente/scaneaza', ['pages' => [UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo 1;')]])->assertUnprocessable();
        $this->postJson('/ro/documente/scaneaza', ['pages' => [UploadedFile::fake()->image('too-wide.jpg', 3000, 100)]])->assertUnprocessable();
        $this->workspace->forceFill(['type' => 'business'])->save();
        $accountant = User::factory()->create();
        $this->workspace->members()->attach($accountant->id, ['role' => 'accountant']);
        $this->actingAs($accountant)->get('/ro/documente/scaneaza')->assertForbidden();
    }

    public function test_ai_quota_reports_its_actual_cause_and_negative_limit_disables_monthly_cap(): void
    {
        config(['vault.plans.free.monthly_ai_calls' => 0]);
        $this->document->forceFill(['ai_requested' => true])->save();
        $service = app(DocumentProcessingService::class);
        $ocrRun = $service->request($this->user, $this->document, 'ocr');
        $ocr = Mockery::mock(OcrProviderInterface::class);
        $ocr->shouldReceive('extract')->once()->andReturn(['text' => $this->text, 'engine' => 'fake']);
        $this->execute($ocrRun, $ocr);
        $this->assertSame('quota_exceeded', $this->document->fresh()->ai_status);
        $this->get('/ro/documente/'.$this->document->id)->assertOk()->assertSee('Cota lunara de procesare');
        $this->assertNull($this->document->fresh()->ai_run_id);
        $this->app->detectEnvironment(fn () => 'local');
        config(['vault.plans.free.monthly_ai_calls' => -1]);
        $run = $service->request($this->user, $this->document, 'ai');
        $this->assertSame('queued', $run->status);
    }

    public function test_pdf_column_spacing_does_not_make_readable_text_fail_quality_checks(): void
    {
        $spaced = str_replace(' ', str_repeat(' ', 25), $this->text);
        $this->readyText($spaced);
        $this->fakeAi();
        $result = app(DocumentAiAnalysis::class)->analyze($this->document->fresh(), 'ro');
        $this->assertFalse($result['review_required']);
        $this->assertSame($spaced, $this->document->fresh()->ocr_text);
        $this->assertFalse(app(TextQuality::class)->good('%%% #@@ ! ! ! '.str_repeat('?', 100)));
    }

    public function test_analysis_copy_is_deleted_and_confirmation_requires_identical_reupload(): void
    {
        $before = $this->workspace->fresh()->storage_used_bytes;
        $file = UploadedFile::fake()->image('two-phase.jpg', 72, 96);
        $draft = app(DocumentLibrary::class)->upload($this->user, $this->workspace, $file, ['title' => 'Two phase', 'analyze_with_ai' => true], draft: true);
        $service = app(DocumentProcessingService::class);
        $ocrRun = $service->request($this->user, $draft, 'ocr');
        $ocr = Mockery::mock(OcrProviderInterface::class);
        $ocr->shouldReceive('extract')->once()->andReturn(['text' => $this->text, 'engine' => 'fake']);
        $this->execute($ocrRun, $ocr);
        $draft->refresh();
        $this->assertTrue(Storage::disk('local')->exists($draft->storage_path)); // AI inca foloseste rezultatul OCR.
        $this->fakeAi();
        $this->execute($draft->aiRun);
        $draft->refresh();
        $this->assertNotNull($draft->source_released_at);
        $this->assertFalse(Storage::disk('local')->exists($draft->storage_path));
        $this->assertSame($before, $this->workspace->fresh()->storage_used_bytes);
        $this->assertSame('draft', $draft->status);
        $this->get('/ro/documente/'.$draft->id.'/descarca')->assertNotFound();
        $this->get('/ro/documente/'.$draft->id.'/sugestii')->assertOk()->assertSee('id="confirm-source"', false)->assertSee('Reselecteaza acelasi fisier original');
        $this->patch('/ro/documente/'.$draft->id, ['title' => 'Confirmed'])->assertSessionHasErrors('file');
        $this->patch('/ro/documente/'.$draft->id, ['title' => 'Confirmed', 'file' => UploadedFile::fake()->image('wrong.jpg', 73, 96)])->assertSessionHasErrors('file');
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame($before, $this->workspace->fresh()->storage_used_bytes);
        $this->patch('/ro/documente/'.$draft->id, ['title' => 'Confirmed', 'metadata_version' => 0, 'ai_run_id' => $draft->ai_run_id, 'file' => $file])->assertSessionHasNoErrors();
        $draft->refresh();
        $this->assertSame('stored', $draft->status);
        $this->assertNull($draft->source_released_at);
        $this->assertTrue(Storage::disk('local')->exists($draft->storage_path));
        $this->assertSame($draft->sha256, hash('sha256', Storage::disk('local')->get($draft->storage_path)));
        app(DraftSource::class)->release($draft->id, $draft->workspace_id);
        $this->assertTrue(Storage::disk('local')->exists($draft->storage_path)); // Curatarea tardiva nu atinge originalul confirmat.
        $this->patch('/ro/documente/'.$draft->id, ['title' => 'Late overwrite', 'metadata_version' => 0, 'file' => $file])->assertSessionHasErrors('processing');
        $this->assertSame('Confirmed', $draft->fresh()->title);
    }

    public function test_released_draft_cancellation_does_not_release_other_document_storage_twice(): void
    {
        $before = $this->workspace->fresh()->storage_used_bytes;
        $draft = app(DocumentLibrary::class)->upload($this->user, $this->workspace, UploadedFile::fake()->image('release.png', 83, 97), ['title' => 'Release'], draft: true);
        $draft->forceFill(['ocr_status' => 'ready'])->save();
        app(DraftSource::class)->release($draft->id, $draft->workspace_id);
        app(DraftSource::class)->release($draft->id, $draft->workspace_id);
        $this->assertSame($before, $this->workspace->fresh()->storage_used_bytes);
        app(DocumentLibrary::class)->discardDraft($draft);
        $this->assertSame($before, $this->workspace->fresh()->storage_used_bytes);
        $this->assertTrue(Storage::disk('local')->exists($this->document->storage_path));
    }

    public function test_scanner_returns_exact_pdf_to_browser_and_late_preview_cannot_recreate_temporary_files(): void
    {
        $response = $this->postJson('/ro/documente/scaneaza', ['pages' => [UploadedFile::fake()->image('page.jpg', 160, 220)], 'analyze_with_ai' => '0', 'retain_file' => true])
            ->assertCreated()->assertHeader('Content-Type', 'application/pdf');
        $meta = json_decode(base64_decode($response->headers->get('X-Vault-Upload')), true);
        $draft = Document::findOrFail($meta['id']);
        $file = UploadedFile::fake()->createWithContent('scan.pdf', $response->getContent());
        $this->assertSame($draft->sha256, hash('sha256', $response->getContent()));
        $oldPreview = new GenerateDocumentPreview($draft->id, $draft->workspace_id, 0);
        $ocr = Mockery::mock(OcrProviderInterface::class);
        $ocr->shouldReceive('extract')->once()->andReturn(['text' => $this->text, 'engine' => 'fake']);
        $this->execute($draft->ocrRun, $ocr);
        $this->assertFalse(Storage::disk('local')->exists($draft->storage_path));
        $oldPreview->handle();
        $this->assertFalse(Storage::disk('local')->exists($draft->storage_path));
        $this->patch('/ro/documente/'.$draft->id, ['title' => 'Confirmed scan', 'file' => $file])->assertSessionHasNoErrors();
        $oldPreview->failed(null);
        $this->assertSame('pending', $draft->fresh()->preview_status);
        $this->assertTrue(Storage::disk('local')->exists($draft->storage_path));
        $this->assertGreaterThan(0, $draft->fresh()->source_revision);
    }

    public function test_duplicate_draft_upload_resumes_only_its_creator_without_new_processing_reservations(): void
    {
        $file = UploadedFile::fake()->image('resumable.png', 76, 91);
        $first = $this->postJson('/ro/documente', ['file' => $file, 'analyze_with_ai' => false])->assertCreated();
        $second = $this->postJson('/ro/documente', ['file' => $file, 'analyze_with_ai' => false])->assertCreated();
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, ProcessingRun::where('document_id', $first->json('id'))->count());
        $this->workspace->forceFill(['type' => 'business'])->save();
        $other = User::factory()->create();
        $this->workspace->members()->attach($other->id, ['role' => 'admin']);
        $this->actingAs($other)->postJson('/ro/documente', ['file' => $file])->assertUnprocessable();
        $this->assertSame(1, ProcessingRun::where('document_id', $first->json('id'))->count());
    }

    public function test_confirmation_quota_failure_keeps_metadata_and_other_files_unchanged(): void
    {
        $before = $this->workspace->fresh()->storage_used_bytes;
        $file = UploadedFile::fake()->image('quota-confirm.png', 98, 103);
        $draft = app(DocumentLibrary::class)->upload($this->user, $this->workspace, $file, ['title' => 'Before confirmation'], draft: true);
        $draft->forceFill(['ocr_status' => 'ready'])->save();
        app(DraftSource::class)->release($draft->id, $draft->workspace_id);
        config(['vault.plans.free.storage_bytes' => $before]);
        $this->patch('/ro/documente/'.$draft->id, ['title' => 'Should not commit', 'file' => $file])->assertSessionHasErrors('file');
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('Before confirmation', $draft->fresh()->title);
        $this->assertSame($before, $this->workspace->fresh()->storage_used_bytes);
        $this->assertFalse(Storage::disk('local')->exists($draft->storage_path));
        $this->assertTrue(Storage::disk('local')->exists($this->document->storage_path));
    }

    public function test_draft_source_cleanup_failure_queues_safe_retry(): void
    {
        $service = Mockery::mock(DraftSource::class)->makePartial();
        $service->shouldReceive('release')->once()->andThrow(new \RuntimeException('Simulated unavailable storage'));
        $service->releaseOrQueue($this->document->id, $this->workspace->id);
        Queue::assertPushedOn('low', ReleaseDraftSource::class);
        (new ReleaseDraftSource($this->document->id, $this->workspace->id))->handle(app(DraftSource::class));
        $this->assertTrue(Storage::disk('local')->exists($this->document->storage_path));
    }

    public function test_receipt_of_legacy_source_releases_it_only_for_authorized_draft_and_correct_size(): void
    {
        $before = $this->workspace->fresh()->storage_used_bytes;
        $file = UploadedFile::fake()->image('legacy.png', 94, 99);
        $draft = app(DocumentLibrary::class)->upload($this->user, $this->workspace, $file, ['title' => 'Legacy draft'], draft: true);
        $draft->forceFill(['ocr_status' => 'ready'])->save();
        $this->get('/ro/documente/'.$draft->id.'/editeaza')->assertOk()->assertSee('data-source-available="1"', false);
        $this->post('/ro/documente/'.$draft->id.'/original-primit', ['bytes' => $draft->size_bytes + 1])->assertStatus(422);
        $this->assertTrue(Storage::disk('local')->exists($draft->storage_path));
        $this->post('/ro/documente/'.$draft->id.'/original-primit', ['bytes' => (string) $draft->size_bytes])->assertOk();
        $this->assertFalse(Storage::disk('local')->exists($draft->storage_path));
        $this->assertSame($before, $this->workspace->fresh()->storage_used_bytes);
        $this->post('/ro/documente/'.$this->document->id.'/original-primit', ['bytes' => $this->document->size_bytes])->assertStatus(409);
        $this->assertTrue(Storage::disk('local')->exists($this->document->storage_path));
    }

    public function test_upload_resumes_ai_that_was_never_started_but_never_repeats_finished_analysis(): void
    {
        $file = UploadedFile::fake()->image('quota-resume.png', 99, 109);
        $draft = app(DocumentLibrary::class)->upload($this->user, $this->workspace, $file, ['title' => 'Quota resume', 'analyze_with_ai' => true], draft: true);
        $draft->forceFill(['ocr_status' => 'ready', 'ocr_text' => $this->text, 'ai_status' => 'failed'])->save();
        $this->postJson('/ro/documente', ['file' => $file, 'analyze_with_ai' => true])->assertCreated();
        $this->assertSame('queued', $draft->fresh()->ai_status);
        $run = $draft->fresh()->aiRun;
        $this->fakeAi();
        $this->execute($run);
        $this->postJson('/ro/documente', ['file' => $file, 'analyze_with_ai' => true])->assertCreated();
        $this->assertSame(1, ProcessingRun::where('document_id', $draft->id)->count());
        Http::assertSentCount(1);
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
