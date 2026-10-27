<?php

namespace Tests\Feature;

use App\Jobs\Documents\GenerateDocumentPreview;
use App\Jobs\Documents\PurgeDocument;
use App\Models\Category;
use App\Models\Document;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\CategorySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\PdfFixture;
use Tests\TestCase;

class DocumentLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([LanguageSeeder::class, CategorySeeder::class, TagSeeder::class]);
        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->workspace = $this->makeWorkspace($this->owner);
        $this->actingAs($this->owner)->withSession(['active_workspace_id' => $this->workspace->id]);
    }

    private function makeWorkspace(User $owner, string $type = 'personal'): Workspace
    {
        $workspace = new Workspace;
        $workspace->forceFill(['owner_id' => $owner->id, 'name' => 'Example private workspace', 'type' => $type])->save();
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        return $workspace;
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['title' => 'Example document', 'file' => UploadedFile::fake()->createWithContent('example.pdf', PdfFixture::make(2)), 'tags' => 'energie, casa'], $changes);
    }

    private function upload(array $changes = []): Document
    {
        $this->post('/ro/documente', $this->payload($changes))->assertRedirect();

        return Document::latest('created_at')->firstOrFail();
    }

    public function test_pdf_upload_is_private_counted_and_original_is_preserved(): void
    {
        $document = $this->upload(['summary' => 'Sumar demonstrativ introdus manual.', 'amount' => '149.99', 'currency' => 'RON', 'description' => 'Uploaded content with ț and ő', 'workspace_id' => 999, 'status' => 'processed', 'storage_path' => 'public/evil']);
        $this->assertSame($this->workspace->id, $document->workspace_id);
        $this->assertSame(2, $document->page_count);
        $this->assertSame('stored', $document->status);
        $this->assertSame(14999, $document->amount_minor);
        $this->assertSame('149.99', $document->decimalAmount());
        $this->assertSame('RON', $document->currency);
        $this->assertSame(2, $document->tags()->count());
        $this->assertSame(PdfFixture::make(2), Storage::disk('local')->get($document->storage_path));
        $this->assertSame($document->size_bytes + $document->preview_bytes, (int) $this->workspace->fresh()->storage_used_bytes);
        $this->assertArrayNotHasKey('storage_path', $document->toArray());
        $response = $this->get('/ro/documente/'.$document->id)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/ro/documente')->assertSee('Sumar demonstrativ introdus manual.')->assertSee('Sumar');
        $response->assertSee('Sumar demonstrativ introdus manual.')->assertDontSee($document->storage_path)->assertSee('149.99 RON')->assertSee('Uploaded content with ț and ő');
    }

    public function test_jpeg_and_png_require_real_image_content(): void
    {
        foreach (['png', 'jpg'] as $extension) {
            $this->post('/ro/documente', $this->payload(['file' => UploadedFile::fake()->image('image.'.$extension, 30, 20)]))->assertRedirect();
        }
        $this->assertDatabaseCount('documents', 2);
        $this->post('/ro/documente', $this->payload(['file' => UploadedFile::fake()->createWithContent('forged.png', '<svg><script>example</script></svg>')]))->assertSessionHasErrors('file');
        $this->post('/ro/documente', $this->payload(['file' => UploadedFile::fake()->createWithContent('forged.pdf', '<html>example</html>')]))->assertSessionHasErrors('file');
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_eleven_page_active_and_corrupt_pdfs_are_rejected_before_storage(): void
    {
        foreach ([PdfFixture::make(11), PdfFixture::make(1, true), "%PDF-1.7\ninvalid"] as $content) {
            $this->post('/ro/documente', $this->payload(['file' => UploadedFile::fake()->createWithContent('example.pdf', $content)]))->assertSessionHasErrors('file');
        }
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame(0, (int) $this->workspace->fresh()->storage_used_bytes);
        Storage::disk('local')->assertDirectoryEmpty('workspaces');
    }

    public function test_encrypted_pdf_is_rejected(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'vaulty-pdf');
        $output = $input.'-encrypted.pdf';
        try {
            file_put_contents($input, PdfFixture::make());
            $process = new Process([config('vault.qpdf_binary'), '--encrypt', 'example-only', 'example-owner', '256', '--', $input, $output]);
            $process->mustRun();
            $this->post('/ro/documente', $this->payload(['file' => UploadedFile::fake()->createWithContent('protected.pdf', file_get_contents($output))]))->assertSessionHasErrors('file');
            $this->assertDatabaseCount('documents', 0);
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }

    public function test_oversized_upload_and_storage_quota_are_enforced_without_leaks(): void
    {
        $this->post('/ro/documente', $this->payload(['file' => UploadedFile::fake()->create('large.pdf', 5121)]))->assertSessionHasErrors('file');
        config(['vault.plans.free.storage_bytes' => 10]);
        $this->post('/ro/documente', $this->payload())->assertSessionHasErrors('file');
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame(0, (int) $this->workspace->fresh()->storage_used_bytes);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_duplicate_upload_including_trash_is_not_counted_twice(): void
    {
        $document = $this->upload();
        $this->post('/ro/documente', $this->payload())->assertSessionHasErrors('file');
        $this->delete('/ro/documente/'.$document->id)->assertRedirect();
        $this->post('/ro/documente', $this->payload())->assertSessionHasErrors('file');
        $this->assertSame(1, Document::withTrashed()->count());
        $this->assertSame($document->size_bytes + $document->preview_bytes, (int) $this->workspace->fresh()->storage_used_bytes);
    }

    public function test_verification_and_current_workspace_membership_are_required(): void
    {
        $this->owner->forceFill(['email_verified_at' => null])->save();
        $this->get('/ro/documente')->assertRedirect('/ro/verifica-email');
        $this->post('/ro/documente', $this->payload())->assertRedirect('/ro/verifica-email');
        $this->owner->markEmailAsVerified();
        $this->workspace->members()->detach($this->owner);
        $this->get('/ro/documente')->assertForbidden();
        $this->post('/ro/documente', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_cross_workspace_and_super_admin_access_cannot_read_or_modify_documents(): void
    {
        $document = $this->upload();
        $foreign = User::factory()->create();
        $foreign->forceFill(['is_super_admin' => true])->save();
        $otherWorkspace = $this->makeWorkspace($foreign);
        $this->actingAs($foreign)->withSession(['active_workspace_id' => $otherWorkspace->id]);
        foreach (['', '/previzualizare', '/descarca', '/editeaza'] as $suffix) {
            $this->get('/ro/documente/'.$document->id.$suffix)->assertNotFound();
        }
        $this->patch('/ro/documente/'.$document->id, ['title' => 'Unwanted change'])->assertNotFound();
        $this->delete('/ro/documente/'.$document->id)->assertNotFound();
        $this->get('/ro/documente')->assertDontSee('Example document');
        $this->assertSame('Example document', $document->fresh()->title);
    }

    public function test_read_only_accountant_and_member_permissions_are_server_side(): void
    {
        $business = $this->makeWorkspace($this->owner, 'business');
        $this->withSession(['active_workspace_id' => $business->id]);
        $document = $this->upload();
        $reader = User::factory()->create();
        $business->members()->attach($reader->id, ['role' => 'accountant']);
        $this->actingAs($reader)->withSession(['active_workspace_id' => $business->id]);
        $this->get('/ro/documente/'.$document->id)->assertOk();
        $this->post('/ro/documente', $this->payload())->assertForbidden();
        $this->patch('/ro/documente/'.$document->id, ['title' => 'Blocked'])->assertForbidden();
        $this->post('/ro/categorii', ['name' => 'Blocked'])->assertForbidden();
        $business->members()->updateExistingPivot($reader->id, ['role' => 'member']);
        $this->patch('/ro/documente/'.$document->id, ['title' => 'Still blocked'])->assertForbidden();
        $this->post('/ro/documente', $this->payload(['file' => UploadedFile::fake()->image('own.png')]))->assertRedirect();
        $own = Document::where('uploaded_by', $reader->id)->sole();
        $this->patch('/ro/documente/'.$own->id, ['title' => 'Own updated'])->assertRedirect();
    }

    public function test_private_downloads_and_previews_have_safe_headers_and_no_public_route(): void
    {
        $document = $this->upload();
        $response = $this->get('/ro/documente/'.$document->id.'/descarca')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame(PdfFixture::make(2), $response->streamedContent());
        (new GenerateDocumentPreview($document->id, $document->workspace_id))->handle();
        $response = $this->get('/ro/documente/'.$document->id.'/previzualizare')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->get('/storage/'.$document->storage_path)->assertNotFound();
    }

    public function test_metadata_and_tags_are_scoped_and_amounts_remain_precise(): void
    {
        $document = $this->upload();
        $foreign = $this->makeWorkspace(User::factory()->create());
        $category = Category::unguarded(fn () => Category::create(['workspace_id' => $foreign->id, 'name' => 'Foreign', 'normalized_name' => 'foreign']));
        $this->patch('/ro/documente/'.$document->id, ['title' => 'Changed', 'category_id' => $category->id])->assertSessionHasErrors('category_id');
        $this->patch('/ro/documente/'.$document->id, ['title' => 'Changed', 'amount' => '9999999999.99', 'currency' => 'HUF', 'tags' => 'tax, Tax'])->assertRedirect();
        $document->refresh();
        $this->assertSame(999999999999, $document->amount_minor);
        $this->assertSame('9999999999.99', $document->decimalAmount());
        $this->assertSame('HUF', $document->currency);
        $this->assertSame(1, $document->tags()->count());
        $this->patch('/ro/documente/'.$document->id, ['title' => 'Changed', 'amount' => '1.999', 'currency' => 'RON'])->assertSessionHasErrors('amount');
        $this->patch('/ro/documente/'.$document->id, ['title' => 'Changed', 'summary' => str_repeat('x', 241)])->assertSessionHasErrors('summary');
        $this->patch('/ro/documente/'.$document->id, ['title' => 'Changed', 'tags' => array_fill(0, 11, 'example')])->assertSessionHasErrors('tags');
    }

    public function test_custom_taxonomy_changes_preserve_documents_and_cannot_mutate_system_categories(): void
    {
        $this->post('/ro/categorii', ['name' => 'Acte de familie'])->assertRedirect('/ro/categorii');
        $category = Category::where('workspace_id', $this->workspace->id)->sole();
        $document = $this->upload(['category_id' => $category->id]);
        $this->patch('/ro/categorii/'.$category->id, ['name' => 'Familie'])->assertRedirect();
        $this->assertSame('Familie', $category->fresh()->name);
        $this->delete('/ro/categorii/'.$category->id)->assertRedirect();
        $this->assertNull($document->fresh()->category_id);
        $this->assertDatabaseCount('documents', 1);
        $system = Category::whereNotNull('system_key')->first();
        $this->delete('/ro/categorii/'.$system->id)->assertNotFound();
        $this->post('/ro/etichete', ['name' => 'Important'])->assertRedirect();
        $tag = Tag::where('name', 'Important')->sole();
        $this->patch('/ro/etichete/'.$tag->id, ['name' => 'Urgent'])->assertRedirect();
        $this->delete('/ro/etichete/'.$tag->id)->assertRedirect();
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_trash_restore_and_idempotent_purge_release_storage_only_after_file_removal(): void
    {
        Queue::fake();
        $document = $this->upload();
        $this->delete('/ro/documente/'.$document->id)->assertRedirect();
        $this->get('/ro/documente/'.$document->id.'/descarca')->assertNotFound();
        $this->post('/ro/documente/'.$document->id.'/restaureaza')->assertRedirect();
        $this->assertFalse($document->fresh()->trashed());
        $this->delete('/ro/documente/'.$document->id)->assertRedirect();
        $this->delete('/ro/documente/'.$document->id.'/sterge-definitiv')->assertSessionHasErrors('confirm');
        $this->delete('/ro/documente/'.$document->id.'/sterge-definitiv', ['confirm' => 1])->assertRedirect();
        Queue::assertPushedOn('low', PurgeDocument::class);
        $this->post('/ro/documente/'.$document->id.'/restaureaza')->assertStatus(409);
        $job = new PurgeDocument($document->id, $document->workspace_id);
        $job->handle();
        $job->handle();
        Storage::disk('local')->assertMissing($document->storage_path);
        $this->assertSame(0, Document::withTrashed()->count());
        $this->assertSame(0, (int) $this->workspace->fresh()->storage_used_bytes);
    }

    public function test_upload_limit_is_shared_across_locales(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post($i % 2 ? '/en/documents' : '/ro/documente', [])->assertSessionHasErrors('file');
        }
        $this->post('/bg/dokumenti', $this->payload())->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_localized_library_pages_have_no_unresolved_keys_and_do_not_enter_discovery(): void
    {
        foreach (['ro' => ['documente', 'categorii'], 'en' => ['documents', 'categories'], 'hu' => ['dokumentumok', 'kategoriak'], 'bg' => ['dokumenti', 'kategorii']] as $locale => $slugs) {
            foreach ($slugs as $slug) {
                $response = $this->get('/'.$locale.'/'.$slug)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
                $this->assertStringNotContainsString('documents.system.', $response->getContent());
                $this->assertStringNotContainsString('documents.intro', $response->getContent());
            }
        }
        $this->get('/sitemap.xml')->assertDontSee('/documente')->assertDontSee('/dokumenti')->assertDontSee('/categories');
    }

    public function test_ten_pages_are_accepted_and_preview_quota_is_idempotent(): void
    {
        Queue::fake();
        $document = $this->upload(['file' => UploadedFile::fake()->createWithContent('ten.pdf', PdfFixture::make(10))]);
        $this->assertSame(10, $document->page_count);
        Queue::assertPushedOn('default', GenerateDocumentPreview::class);
        $job = new GenerateDocumentPreview($document->id, $document->workspace_id);
        $job->handle();
        $document->refresh();
        $this->assertSame('ready', $document->preview_status);
        $this->assertGreaterThan(0, $document->preview_bytes);
        $used = (int) $this->workspace->fresh()->storage_used_bytes;
        $this->assertSame($document->size_bytes + $document->preview_bytes, $used);
        $job->handle();
        $this->assertSame($used, (int) $this->workspace->fresh()->storage_used_bytes);
        $this->get('/ro/documente/'.$document->id.'/previzualizare?page=10')->assertOk();
        $this->get('/ro/documente/'.$document->id.'/previzualizare?page=11')->assertSessionHasErrors('page');
        $document->delete();
        $document->forceFill(['purge_requested_at' => now()])->save();
        (new PurgeDocument($document->id, $document->workspace_id))->handle();
        $this->assertSame(0, (int) $this->workspace->fresh()->storage_used_bytes);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_preview_quota_failure_is_permanent_and_keeps_the_original(): void
    {
        Queue::fake();
        $document = $this->upload();
        config(['vault.plans.free.storage_bytes' => $document->size_bytes]);
        (new GenerateDocumentPreview($document->id, $document->workspace_id))->handle();
        $this->assertSame('failed', $document->fresh()->preview_status);
        $this->assertSame($document->size_bytes, (int) $this->workspace->fresh()->storage_used_bytes);
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->get('/ro/documente/'.$document->id.'/descarca')->assertOk();
    }

    public function test_system_tags_are_shared_translated_and_immutable_from_workspace_routes(): void
    {
        $this->seed(TagSeeder::class);
        $this->assertSame(8, Tag::whereNotNull('system_key')->count());
        $tag = Tag::where('system_key', 'important')->sole();
        $document = $this->upload(['tag_ids' => [$tag->id], 'tags' => 'Important, familie']);
        $this->assertSame(2, $document->tags()->count());
        $this->assertTrue($document->tags()->whereKey($tag->id)->exists());
        $this->patch('/ro/etichete/'.$tag->id, ['name' => 'Blocked'])->assertNotFound();
        $this->delete('/ro/etichete/'.$tag->id)->assertNotFound();
        $foreign = $this->makeWorkspace(User::factory()->create());
        $foreignTag = Tag::unguarded(fn () => Tag::create(['workspace_id' => $foreign->id, 'name' => 'Foreign', 'normalized_name' => 'foreign']));
        $this->patch('/ro/documente/'.$document->id, ['title' => 'Same', 'tag_ids' => [$foreignTag->id]])->assertSessionHasErrors('tags');
        foreach (['ro', 'en', 'hu', 'bg'] as $locale) {
            $this->assertIsArray(trans('documents.system', [], $locale));
            $this->assertSame(array_keys(trans('documents', [], 'ro')), array_keys(trans('documents', [], $locale)));
            $this->assertSame(array_keys(trans('documents.system', [], 'ro')), array_keys(trans('documents.system', [], $locale)));
        }
    }

    public function test_category_seed_is_idempotent_and_personal_categories_do_not_leak_into_business(): void
    {
        $this->seed(CategorySeeder::class);
        $this->assertDatabaseCount('categories', 30);
        $businessCategory = Category::where('scope', 'business')->where('system_key', 'accounting')->sole();
        $this->post('/ro/documente', $this->payload(['category_id' => $businessCategory->id]))->assertSessionHasErrors('category_id');
        $this->assertDatabaseCount('documents', 0);
    }
}
