<?php

namespace Tests\Feature;

use App\Notifications\BackupAlert;
use App\Services\Backup\BackupBarrier;
use App\Services\Backup\BackupMonitor;
use App\Services\Backup\BackupProcess;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\BackupState;
use App\Services\Backup\ResticRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/vaulty-backup-test-'.bin2hex(random_bytes(8));
        config(['backup.directory' => $this->directory, 'backup.enabled' => false, 'backup.single_host' => true, 'backup.alert_email' => 'operator@example.test']);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function production(): void
    {
        $this->app['env'] = 'production';
        config(['backup.enabled' => true]);
    }

    public function test_local_and_testing_execution_are_refused_even_when_enabled(): void
    {
        config(['backup.enabled' => true]);
        $this->artisan('vault:backup')->assertFailed();
        $this->artisan('vault:backup', ['operation' => 'init', '--no-interaction' => true])->assertFailed();
        $this->assertFalse(app(BackupBarrier::class)->active());
    }

    public function test_missing_configuration_never_starts_a_process(): void
    {
        $this->production();
        $this->mock(BackupProcess::class)->shouldNotReceive('run');
        $this->artisan('vault:backup')->assertFailed();
    }

    private function runner(): BackupRunner
    {
        $runner = \Mockery::mock(BackupRunner::class)->makePartial();
        $runner->shouldReceive('snapshot')->once()->andReturnUsing(function ($stage) {
            file_put_contents($stage.'/example', 'fake');
        });

        return $runner;
    }

    public function test_backup_is_verified_before_retention_and_resumes_application_before_upload(): void
    {
        $this->production();
        $repo = $this->mock(ResticRepository::class);
        $repo->shouldReceive('configured')->once();
        $repo->shouldReceive('run')->once()->ordered()->with(\Mockery::on(fn ($args) => $args[0] === 'backup'))->andReturnUsing(function () {
            $this->assertFileDoesNotExist($this->directory.'/pending');
            $lock = app(BackupBarrier::class)->shared();
            $this->assertIsResource($lock);
            app(BackupBarrier::class)->release($lock);

            return json_encode(['message_type' => 'summary', 'snapshot_id' => str_repeat('a', 64)]);
        });
        $repo->shouldReceive('run')->once()->ordered()->with(['check'])->andReturn('');
        $repo->shouldReceive('run')->once()->ordered()->with(\Mockery::on(fn ($args) => $args[0] === 'tag'))->andReturn('');
        $repo->shouldReceive('run')->once()->ordered()->with(\Mockery::on(fn ($args) => $args[0] === 'snapshots'))->andReturn(json_encode([['id' => str_repeat('b', 64)]]));
        $repo->shouldReceive('run')->once()->ordered()->with(\Mockery::on(fn ($args) => $args[0] === 'forget' && in_array('2', $args, true) && in_array('host', $args, true)))->andReturn('');
        $this->runner()->run();
        $state = app(BackupState::class)->read();
        $this->assertSame('succeeded', $state['status']);
        $this->assertSame(str_repeat('b', 64), $state['snapshot_id']);
        $this->assertDirectoryDoesNotExist($this->directory.'/staging');
    }

    public function test_failed_upload_keeps_previous_success_and_never_prunes(): void
    {
        $this->production();
        app(BackupState::class)->update(['last_success_at' => 123]);
        $repo = $this->mock(ResticRepository::class);
        $repo->shouldReceive('configured');
        $repo->shouldReceive('run')->once()->with(\Mockery::on(fn ($args) => $args[0] === 'backup'))->andThrow(new \RuntimeException('PRIVATE-CREDENTIAL-EXAMPLE'));
        try {
            $this->runner()->run();
            $this->fail('Expected safe failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('BACKUP_FAILED', $e->getMessage());
        }
        $state = app(BackupState::class)->read();
        $this->assertSame(123, $state['last_success_at']);
        $this->assertSame('failed', $state['status']);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($state));
        $this->assertFileDoesNotExist($this->directory.'/pending');
        $this->assertDirectoryDoesNotExist($this->directory.'/staging');
    }

    public function test_verification_failure_does_not_delete_old_snapshots(): void
    {
        $this->production();
        $repo = $this->mock(ResticRepository::class);
        $repo->shouldReceive('configured');
        $repo->shouldReceive('run')->once()->with(\Mockery::on(fn ($a) => $a[0] === 'backup'))->andReturn(json_encode(['message_type' => 'summary', 'snapshot_id' => str_repeat('a', 64)]));
        $repo->shouldReceive('run')->once()->with(['check'])->andThrow(new \RuntimeException('failure'));
        $this->expectException(\RuntimeException::class);
        $this->runner()->run();
    }

    public function test_slow_monitor_alerts_once_while_backup_lock_is_busy(): void
    {
        $this->production();
        $store = app(BackupState::class);
        $store->update(['run_id' => 'test', 'status' => 'running', 'started_at' => now()->timestamp - 901]);
        $lock = $store->runLock();
        try {
            app(BackupMonitor::class)->check();
            app(BackupMonitor::class)->check();
        } finally {
            app(BackupBarrier::class)->release($lock);
        }
        Notification::assertSentOnDemand(BackupAlert::class, fn ($notice) => $notice->reason === 'slow');
        Notification::assertCount(1);
        $this->assertSame('running', $store->read()['status']);
    }

    public function test_interrupted_backup_releases_marker_and_removes_plaintext_staging(): void
    {
        $this->production();
        $store = app(BackupState::class);
        $store->update(['run_id' => 'dead', 'status' => 'running', 'started_at' => now()->timestamp - 1000]);
        file_put_contents($this->directory.'/pending', 'dead');
        mkdir($this->directory.'/staging');
        file_put_contents($this->directory.'/staging/database.sql', 'PRIVATE');
        app(BackupMonitor::class)->check();
        $this->assertSame('interrupted', $store->read()['status']);
        $this->assertFileDoesNotExist($this->directory.'/pending');
        $this->assertDirectoryDoesNotExist($this->directory.'/staging');
    }

    public function test_queue_barrier_does_not_pop_jobs_when_snapshot_is_pending(): void
    {
        $this->production();
        $barrier = app(BackupBarrier::class);
        $directory = app(BackupState::class)->directory();
        $this->assertTrue($barrier->loop());
        file_put_contents($directory.'/pending', 'test');
        $this->assertFalse($barrier->loop());
        $exclusive = $barrier->exclusive(0);
        $this->assertIsResource($exclusive);
        $barrier->release($exclusive);
        unlink($directory.'/pending');
        $this->assertTrue($barrier->loop());
        $barrier->idle();
    }

    public function test_http_barrier_refuses_requests_only_during_exclusive_snapshot(): void
    {
        $this->production();
        $barrier = app(BackupBarrier::class);
        $exclusive = $barrier->exclusive(0);
        try {
            $this->get('/up')->assertStatus(503)->assertHeader('Retry-After', '30');
        } finally {
            $barrier->release($exclusive);
        }
        $this->get('/up')->assertOk();
    }

    public function test_parallel_backups_are_refused_without_changing_existing_state(): void
    {
        $this->production();
        $store = app(BackupState::class);
        $store->update(['status' => 'running', 'run_id' => 'other']);
        $lock = $store->runLock();
        $this->mock(ResticRepository::class)->shouldReceive('configured');
        try {
            app(BackupRunner::class)->run();
            $this->fail('Expected lock failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('BACKUP_ALREADY_RUNNING', $e->getMessage());
        } finally {
            app(BackupBarrier::class)->release($lock);
        }
        $this->assertSame('other', $store->read()['run_id']);
    }

    public function test_monitor_detects_a_missing_nightly_run(): void
    {
        $this->production();
        $this->travelTo(CarbonImmutable::parse('2026-10-28 23:50:00', 'Europe/Bucharest'));
        app(BackupState::class)->update(['last_success_at' => now()->subDay()->timestamp, 'started_at' => now()->subDay()->timestamp, 'status' => 'succeeded']);
        app(BackupMonitor::class)->check();
        Notification::assertSentOnDemand(BackupAlert::class, fn ($notice) => $notice->reason === 'missing');
    }

    public function test_unconfigured_monitor_does_not_send_or_claim_an_alert_was_sent(): void
    {
        $this->production();
        config(['backup.alert_email' => '']);
        app(BackupMonitor::class)->check();
        Notification::assertNothingSent();
        $this->assertSame('BACKUP_ALERT_EMAIL_MISSING', app(BackupState::class)->read()['monitor_error']);
        $this->assertArrayNotHasKey('alert_key', app(BackupState::class)->read());
    }
}
