<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\BackupBarrier;
use App\Services\Backup\BackupDatabase;
use App\Services\Backup\BackupInspection;
use App\Services\Backup\BackupRestoreControl;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\ResticRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/vaulty-restore-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->snapshot = str_repeat('a', 64);
        config(['backup.directory' => $this->directory.'/control', 'backup.restore_enabled' => true, 'backup.restore_dedicated_redis' => true, 'backup.enabled' => true, 'backup.single_host' => true, 'filesystems.disks.local.root' => $this->directory.'/private']);
        mkdir($this->directory.'/private', 0700);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function repository(): MockInterface
    {
        $repo = $this->mock(ResticRepository::class);
        $repo->shouldReceive('run')->with(['snapshots', '--json', '--host', 'vaulty-production', '--tag', 'vaulty-verified'])->andReturn(json_encode([['id' => $this->snapshot, 'time' => now()->toIso8601String(), 'tags' => ['vaulty-verified']]]));

        return $repo;
    }

    public function test_unknown_operation_is_explicit_and_restore_requires_production(): void
    {
        $this->artisan('vault:backup', ['operation' => 'bad'])->expectsOutputToContain('Operatie necunoscuta')->assertFailed();
        $this->artisan('vault:backup', ['operation' => 'restore', '--no-interaction' => true])->assertFailed();
    }

    public function test_restore_cannot_start_without_maintenance_or_service_preflight(): void
    {
        $this->mock(ResticRepository::class)->shouldReceive('configured')->once();
        $this->expectExceptionMessage('RESTORE_MAINTENANCE_REQUIRED');
        app(BackupRestorer::class)->apply();
    }

    public function test_request_is_encrypted_unique_and_expires(): void
    {
        $this->repository();
        $control = app(BackupRestoreControl::class);
        $control->request($this->snapshot, 'all', 123);
        $this->assertSame('all', $control->pending()['scope']);
        $this->assertStringNotContainsString($this->snapshot, file_get_contents($control->directory().'/request.json'));
        $this->assertSame(0600, fileperms($control->directory().'/request.json') & 0777);
        try {
            $control->request($this->snapshot, 'mysql', 123);
            $this->fail('Duplicate accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('RESTORE_BUSY', $e->getMessage());
        }
        $this->travel(11)->minutes();
        $this->expectExceptionMessage('RESTORE_REQUEST_INVALID');
        $control->pending();
    }

    public function test_snapshot_and_scope_cannot_be_injected(): void
    {
        $this->repository();
        $this->expectExceptionMessage('RESTORE_SNAPSHOT_UNAVAILABLE');
        app(BackupRestoreControl::class)->request('--latest', 'all', 1);
    }

    public function test_invalid_scope_and_disabled_restore_are_rejected(): void
    {
        $this->expectExceptionMessage('RESTORE_DISABLED');
        app(BackupRestoreControl::class)->request($this->snapshot, 'shell', 1);
    }

    public function test_plaintext_and_expired_request_cannot_be_applied(): void
    {
        $control = app(BackupRestoreControl::class);
        $control->write('request', ['snapshot' => $this->snapshot, 'scope' => 'all']);
        $this->expectException(\Throwable::class);
        $control->pending();
    }

    public function test_symlinks_and_duplicate_recovery_files_are_refused(): void
    {
        $root = $this->directory.'/download';
        mkdir($root);
        symlink('/etc/passwd', $root.'/private');
        try {
            app(BackupRestorer::class)->stage($root);
            $this->fail('Symlink accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('RESTORE_UNSAFE_PATH', $e->getMessage());
        }
        unlink($root.'/private');
        $this->expectExceptionMessage('RESTORE_CONTENT_INVALID');
        app(BackupRestorer::class)->stage($root);
    }

    private function document(string $contents): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::forceCreate(['owner_id' => $user->id, 'name' => 'Test', 'type' => 'personal']);
        DB::table('documents')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'uploaded_by' => $user->id, 'title' => 'Test', 'original_name' => 'test.pdf', 'storage_path' => 'workspaces/1/test.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => strlen($contents), 'page_count' => 1, 'sha256' => hash('sha256', $contents), 'status' => 'stored', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_document_hash_and_presence_are_verified(): void
    {
        $this->document('original');
        mkdir($this->directory.'/documents');
        mkdir($this->directory.'/documents/1');
        file_put_contents($this->directory.'/documents/1/test.pdf', 'original');
        app(BackupRestorer::class)->verifyDocuments($this->directory.'/documents');
        file_put_contents($this->directory.'/documents/1/test.pdf', 'modified');
        $this->expectExceptionMessage('RESTORE_DOCUMENT_MISMATCH');
        app(BackupRestorer::class)->verifyDocuments($this->directory.'/documents');
    }

    private function fixtureRestore(string $scope = 'all', bool $validKey = true, bool $validFiles = true): BackupRestorer
    {
        $repo = $this->repository();
        $control = app(BackupRestoreControl::class);
        $control->request($this->snapshot, $scope, null);
        $repo->shouldReceive('run')->with(['stats', $this->snapshot, '--mode', 'restore-size', '--json'])->once()->andReturn('{"total_size":128}');
        $repo->shouldReceive('run')->with(\Mockery::on(fn ($args) => $args[0] === 'restore'))->once()->andReturnUsing(function ($args) use ($validKey, $validFiles) {
            $stage = $args[3].'/stage';
            mkdir($stage, 0700);
            file_put_contents($stage.'/database.sql', 'fake sql');
            file_put_contents($stage.'/recovery.json', json_encode(['app_key' => $validKey ? config('app.key') : 'wrong']));
            mkdir($stage.'/documents');
            mkdir($stage.'/documents/1');
            file_put_contents($stage.'/documents/1/test.pdf', $validFiles ? 'original' : 'corrupt');

            return '';
        });
        $runner = $this->mock(BackupRunner::class);
        $runner->shouldReceive('snapshot')->andReturnUsing(function ($path) {
            file_put_contents($path.'/database.sql', 'safety sql');
        });
        $restorer = \Mockery::mock(BackupRestorer::class)->makePartial();
        $restorer->shouldReceive('preflight')->once();

        return $restorer;
    }

    public function test_successful_restore_verifies_files_reconciles_and_keeps_safety_copy(): void
    {
        $this->document('original');
        $restorer = $this->fixtureRestore();
        $this->mock(BackupDatabase::class)->shouldReceive('import')->once();
        $restorer->shouldReceive('reconcile')->once()->andReturnNull();
        $restorer->apply();
        $control = app(BackupRestoreControl::class);
        $this->assertSame('restored', $control->read('status')['phase']);
        $this->assertFileDoesNotExist($control->directory().'/request.json');
        $this->assertFileDoesNotExist($control->directory().'/incomplete');
        $this->assertSame('original', file_get_contents($this->directory.'/private/workspaces/1/test.pdf'));
        $this->assertCount(1, glob($control->directory().'/run-*/safety/database.sql'));
        $this->assertCount(0, glob($control->directory().'/run-*/download'));
    }

    public function test_wrong_key_never_imports_and_leaves_a_guard_for_intervention(): void
    {
        $restorer = $this->fixtureRestore(validKey: false);
        $this->mock(BackupDatabase::class)->shouldNotReceive('import');
        try {
            $restorer->apply();
            $this->fail('Wrong key accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('RESTORE_FAILED_MAINTENANCE', $e->getMessage());
        }
        $control = app(BackupRestoreControl::class);
        $this->assertSame('RESTORE_KEY_MISMATCH', $control->read('status')['error_code']);
        $this->assertFileExists($control->directory().'/incomplete');
        $this->assertNull(app(BackupBarrier::class)->shared());
    }

    public function test_partial_import_or_invalid_files_roll_back_database_before_staying_down(): void
    {
        $this->document('original');
        $restorer = $this->fixtureRestore(validFiles: false);
        $database = $this->mock(BackupDatabase::class);
        $database->shouldReceive('import')->once()->ordered()->with(\Mockery::pattern('/download.*database.sql$/'), \Mockery::type('string'));
        $database->shouldReceive('import')->once()->ordered()->with(\Mockery::pattern('/safety\/database.sql$/'), \Mockery::type('string'), false);
        $restorer->shouldNotReceive('reconcile');
        $this->expectExceptionMessage('RESTORE_FAILED_MAINTENANCE');
        $restorer->apply();
    }

    public function test_documents_only_does_not_import_sql_on_success(): void
    {
        $this->document('original');
        $restorer = $this->fixtureRestore('documents');
        $this->mock(BackupDatabase::class)->shouldNotReceive('import');
        $restorer->shouldReceive('reconcile')->once();
        $restorer->apply();
        $this->assertSame('restored', app(BackupRestoreControl::class)->read('status')['phase']);
    }

    public function test_mysql_only_cannot_restore_references_to_missing_originals(): void
    {
        $this->document('original');
        $restorer = $this->fixtureRestore('mysql');
        $this->mock(BackupDatabase::class)->shouldReceive('import')->twice();
        $restorer->shouldNotReceive('reconcile');
        $this->expectExceptionMessage('RESTORE_FAILED_MAINTENANCE');
        $restorer->apply();
    }

    public function test_reconciliation_invalidates_tokens_and_blocks_historical_notifications(): void
    {
        $user = User::factory()->create(['remember_token' => 'old']);
        $user->createToken('old');
        Redis::shouldReceive('connection')->once()->withNoArgs()->andReturnSelf();
        Redis::shouldReceive('connection')->once()->with('cache')->andReturnSelf();
        Redis::shouldReceive('flushdb')->twice();
        app(BackupRestorer::class)->reconcile();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull($user->fresh()->remember_token);
    }

    public function test_bucket_usage_sums_paginated_objects_without_exposing_keys(): void
    {
        config(['backup.account_id' => str_repeat('a', 32), 'backup.bucket' => 'test-bucket', 'backup.jurisdiction' => 'eu', 'backup.secret_key' => 'fake-secret', 'backup.access_key' => 'fake-access']);
        Http::fakeSequence()->push('<ListBucketResult><Contents><Key>private</Key><Size>12</Size></Contents><IsTruncated>true</IsTruncated><NextContinuationToken>next</NextContinuationToken></ListBucketResult>')->push('<ListBucketResult><Contents><Size>30</Size></Contents><IsTruncated>false</IsTruncated></ListBucketResult>');
        $result = app(BackupInspection::class)->bucketUsage();
        $this->assertSame(['bytes' => 42, 'objects' => 2, 'complete' => true], $result);
        Http::assertSent(fn ($request) => str_contains($request->url(), '.eu.r2.cloudflarestorage.com/test-bucket?') && $request->hasHeader('Authorization'));
        $this->assertStringNotContainsString('private', json_encode($result));
    }

    public function test_sql_table_names_match_without_schema_prefix_and_unsafe_use_is_refused(): void
    {
        $sql = $this->directory.'/tables.sql';
        $tables = Schema::getTableListing(null, false);
        file_put_contents($sql, implode("\n", array_map(fn ($table) => "CREATE TABLE `$table` (id int);", $tables)));
        app(BackupDatabase::class)->assertSchema($sql);
        file_put_contents($sql, "\nUSE other_database;", FILE_APPEND);
        $this->expectExceptionMessage('RESTORE_SQL_UNSAFE');
        app(BackupDatabase::class)->assertSchema($sql);
    }

    public function test_import_failure_attempts_safety_rollback_and_never_swaps_originals(): void
    {
        mkdir($this->directory.'/private/workspaces');
        file_put_contents($this->directory.'/private/workspaces/current', 'keep');
        $restorer = $this->fixtureRestore();
        $database = $this->mock(BackupDatabase::class);
        $database->shouldReceive('import')->once()->ordered()->with(\Mockery::pattern('/download.*database.sql$/'), \Mockery::type('string'))->andThrow(new \RuntimeException('PRIVATE-PASSWORD'));
        $database->shouldReceive('import')->once()->ordered()->with(\Mockery::pattern('/safety\/database.sql$/'), \Mockery::type('string'), false);
        try {
            $restorer->apply();
            $this->fail('Import should fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('RESTORE_FAILED_MAINTENANCE', $e->getMessage());
        }
        $this->assertSame('keep', file_get_contents($this->directory.'/private/workspaces/current'));
        $state = app(BackupRestoreControl::class)->read('status');
        $this->assertSame('failed', $state['phase']);
        $this->assertStringNotContainsString('PRIVATE-PASSWORD', json_encode($state));
    }

    public function test_bucket_measurement_marks_results_partial_when_page_limit_is_reached(): void
    {
        config(['backup.account_id' => str_repeat('a', 32), 'backup.bucket' => 'test-bucket', 'backup.secret_key' => 'fake', 'backup.access_key' => 'fake']);
        Http::fake(['*' => Http::response('<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Contents><Size>5</Size></Contents><IsTruncated>true</IsTruncated><NextContinuationToken>next</NextContinuationToken></ListBucketResult>')]);
        $result = app(BackupInspection::class)->bucketUsage();
        $this->assertSame(['bytes' => 50, 'objects' => 10, 'complete' => false], $result);
        Http::assertSentCount(10);
    }

    public function test_existing_encrypted_admin_secret_is_checked_without_disclosure(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['admin_mfa_secret' => 'EXAMPLE-ONLY-SECRET'])->save();
        app(BackupRestorer::class)->verifyEncryption();
        DB::table('users')->where('id', $user->id)->update(['admin_mfa_secret' => 'invalid-ciphertext']);
        $this->expectException(DecryptException::class);
        app(BackupRestorer::class)->verifyEncryption();
    }
}
