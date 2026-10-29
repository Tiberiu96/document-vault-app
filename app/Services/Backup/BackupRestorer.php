<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackupRestorer
{
    public function preflight(): void
    {
        app(ResticRepository::class)->configured();
        if (! config('backup.restore_enabled') || ! app()->isDownForMaintenance()) {
            throw new \RuntimeException('RESTORE_MAINTENANCE_REQUIRED');
        }
        foreach (['vaulty-horizon.service', 'vaulty-scheduler.service', 'php8.3-fpm.service', 'vaulty-backup.service', 'vaulty-backup-monitor.service'] as $unit) {
            $process = new Process(['/usr/bin/systemctl', 'show', $unit, '--property=ActiveState', '--value']);
            $process->setTimeout(5)->run();
            if (! $process->isSuccessful() || ! in_array(trim($process->getOutput()), ['inactive', 'failed'], true)) {
                throw new \RuntimeException('RESTORE_SERVICES_ACTIVE');
            }
        }
        // FLUSHDB este limitat la cele doua baze Redis dedicate Vaulty, confirmate explicit.
        if (! config('backup.restore_dedicated_redis') || config('session.driver') !== 'redis' || config('cache.default') !== 'redis' || config('queue.connections.redis.connection') !== 'default' || config('cache.stores.redis.connection') !== 'cache' || ! in_array(config('session.connection'), [null, 'default', 'cache'], true) || config('database.redis.default.database') == config('database.redis.cache.database')) {
            throw new \RuntimeException('RESTORE_REDIS_CONFIGURATION');
        }
    }

    public function apply(): void
    {
        $this->preflight();
        $control = app(BackupRestoreControl::class);
        $request = $control->pending();
        $barrier = app(BackupBarrier::class);
        $lock = app(BackupState::class)->runLock();
        if (! $lock) {
            throw new \RuntimeException('RESTORE_BUSY');
        }
        $exclusive = null;
        $oldMask = umask(0077);
        $path = $control->directory().'/run-'.$request['id'];
        $live = Storage::disk('local')->path('workspaces');
        $prepared = dirname($live).'/.restore-'.$request['id'];
        $previous = dirname($live).'/.before-restore-'.$request['id'];
        $databaseChanged = false;
        $filesMoved = false;
        $safetyReady = false;
        $preparedCreated = false;
        try {
            if (is_file($control->directory().'/incomplete') || ! mkdir($path, 0700)) {
                throw new \RuntimeException('RESTORE_BUSY');
            }
            rename($control->directory().'/request.json', $path.'/request.json');
            file_put_contents($control->directory().'/incomplete', $request['id']);
            $control->write('status', [...$request, 'phase' => 'downloading']);
            $exclusive = $barrier->exclusive(config('backup.drain_seconds'));
            $repo = app(ResticRepository::class);
            if (! in_array($request['snapshot'], array_column($control->snapshots(), 'id'), true)) {
                throw new \RuntimeException('RESTORE_SNAPSHOT_UNAVAILABLE');
            }
            $stats = json_decode($repo->run(['stats', $request['snapshot'], '--mode', 'restore-size', '--json']), true, 512, JSON_THROW_ON_ERROR);
            if (! isset($stats['total_size']) || disk_free_space($path) < 4 * $stats['total_size'] + 268435456) {
                throw new \RuntimeException('RESTORE_DISK_SPACE');
            }
            mkdir($path.'/download', 0700);
            $repo->run(['restore', $request['snapshot'], '--target', $path.'/download']);
            $stage = $this->stage($path.'/download');
            $key = json_decode(file_get_contents($stage.'/recovery.json'), true, 512, JSON_THROW_ON_ERROR)['app_key'] ?? '';
            if (! is_string($key) || $key === '' || ! hash_equals((string) config('app.key'), $key)) {
                throw new \RuntimeException('RESTORE_KEY_MISMATCH');
            }
            $this->assertTree($live);
            if (file_exists($prepared) || file_exists($previous)) {
                throw new \RuntimeException('RESTORE_UNSAFE_PATH');
            }
            $control->write('status', [...$request, 'phase' => 'safety_copy']);
            mkdir($path.'/safety', 0700);
            app(BackupRunner::class)->snapshot($path.'/safety');
            $safetyReady = true;
            $migrations = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();

            if (in_array($request['scope'], ['all', 'documents'], true)) {
                mkdir($prepared, 0700);
                $preparedCreated = true;
                if (is_dir($stage.'/documents')) {
                    app(BackupProcess::class)->run(['cp', '--archive', '--reflink=auto', '--no-dereference', $stage.'/documents/.', $prepared]);
                }
            }
            $control->write('status', [...$request, 'phase' => 'applying']);
            if (in_array($request['scope'], ['all', 'mysql'], true)) {
                $databaseChanged = true; // Inclusiv un import partial necesita rollback.
                app(BackupDatabase::class)->import($stage.'/database.sql', $path);
                if (DB::table('migrations')->orderBy('migration')->pluck('migration')->all() !== $migrations) {
                    throw new \RuntimeException('RESTORE_SCHEMA_MISMATCH');
                }
            }
            $this->verifyDocuments($request['scope'] === 'mysql' ? $live : $prepared);
            $this->verifyEncryption();
            if ($request['scope'] !== 'mysql') {
                if (is_dir($live) && ! rename($live, $previous)) {
                    throw new \RuntimeException('RESTORE_FILES_SWAP');
                }
                $filesMoved = true;
                if (! rename($prepared, $live)) {
                    throw new \RuntimeException('RESTORE_FILES_SWAP');
                }
            }
            $control->write('status', [...$request, 'phase' => 'reconciling']);
            $databaseChanged = true;
            $this->reconcile();
            // Repository-ul R2 si copia locala de siguranta nu sunt sterse de restore.
            File::deleteDirectory($path.'/download');
            $control->write('status', [...$request, 'phase' => 'restored', 'finished_at' => now()->timestamp]);
            unlink($control->directory().'/incomplete');
        } catch (\Throwable $exception) {
            $rolledBack = false;
            try {
                if ($databaseChanged && $safetyReady) {
                    app(BackupDatabase::class)->import($path.'/safety/database.sql', $path, false);
                }
                if ($filesMoved) {
                    if (is_dir($live)) {
                        File::deleteDirectory($live);
                    }
                    if (is_dir($previous) && ! rename($previous, $live)) {
                        throw new \RuntimeException;
                    }
                }
                $rolledBack = true;
            } catch (\Throwable) {
                // Nu reporni serviciile dupa rollback nereusit.
            }
            $allowed = ['RESTORE_DOCUMENT_MISMATCH', 'RESTORE_KEY_MISMATCH', 'RESTORE_SCHEMA_MISMATCH', 'RESTORE_DISK_SPACE', 'RESTORE_UNSAFE_PATH', 'RESTORE_SNAPSHOT_UNAVAILABLE', 'RESTORE_CONTENT_INVALID'];
            $code = in_array($exception->getMessage(), $allowed, true) ? $exception->getMessage() : 'RESTORE_FAILED_MAINTENANCE';
            $control->write('status', [...$request, 'phase' => 'failed', 'finished_at' => now()->timestamp, 'error_code' => $rolledBack ? $code : 'RESTORE_ROLLBACK_REQUIRED']);
            throw new \RuntimeException('RESTORE_FAILED_MAINTENANCE');
        } finally {
            if ($preparedCreated && is_dir($prepared)) {
                File::deleteDirectory($prepared);
            }
            $barrier->release($exclusive);
            $barrier->release($lock);
            umask($oldMask);
        }
    }

    public function stage(string $target): string
    {
        $this->assertTree($target);
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getFilename() === 'recovery.json') {
                $found[] = $file->getPath();
            }
        }
        if (count($found) !== 1 || ! is_file($found[0].'/database.sql') || filesize($found[0].'/database.sql') < 1) {
            throw new \RuntimeException('RESTORE_CONTENT_INVALID');
        }

        return $found[0];
    }

    public function assertTree(string $root): void
    {
        if (is_link($root)) {
            throw new \RuntimeException('RESTORE_UNSAFE_PATH');
        }
        if (! is_dir($root)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
            if ($file->isLink() || (! $file->isDir() && ! $file->isFile())) {
                throw new \RuntimeException('RESTORE_UNSAFE_PATH');
            }
        }
    }

    public function verifyDocuments(string $root): void
    {
        $this->assertTree($root);
        foreach (DB::table('documents')->whereNull('deleted_at')->where('status', 'stored')->cursor() as $doc) {
            $relative = $doc->storage_path;
            if (! str_starts_with($relative, 'workspaces/') || str_contains($relative, '..') || str_contains($relative, "\0")) {
                throw new \RuntimeException('RESTORE_DOCUMENT_MISMATCH');
            }
            $file = $root.'/'.substr($relative, strlen('workspaces/'));
            if (! is_file($file) || filesize($file) !== (int) $doc->size_bytes || ! hash_equals($doc->sha256, hash_file('sha256', $file))) {
                throw new \RuntimeException('RESTORE_DOCUMENT_MISMATCH');
            }
        }
    }

    public function verifyEncryption(): void
    {
        foreach ([['documents', 'ocr_text'], ['processing_runs', 'result'], ['users', 'admin_mfa_secret']] as [$table, $column]) {
            foreach (DB::table($table)->whereNotNull($column)->select($column)->cursor() as $row) {
                Crypt::decryptString($row->$column);
            }
        }
    }

    public function reconcile(): void
    {
        // Orice sesiune, token si job vechi devine invalid; nu retransmite automat emailuri.
        DB::table('personal_access_tokens')->delete();
        DB::table('users')->update(['remember_token' => null, 'admin_recovery_hashes' => '[]']);
        DB::table('users')->whereNotNull('admin_mfa_confirmed_at')->update(['admin_mfa_last_counter' => intdiv(now()->timestamp, 30), 'admin_mfa_version' => DB::raw('admin_mfa_version + 1')]);
        DB::table('reminder_deliveries')->whereIn('status', ['pending', 'processing'])->update(['status' => 'uncertain', 'failure_code' => 'RESTORED_REQUIRES_REVIEW', 'finished_at' => now()]);
        DB::table('processing_runs')->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'error_code' => 'RESTORED_REQUIRES_REVIEW', 'completed_at' => now()]);
        foreach (['ocr_status', 'ai_status'] as $column) {
            DB::table('documents')->whereIn($column, ['queued', 'running'])->update([$column => 'failed']);
        }
        DB::table('processing_usages')->update(['ocr_reserved' => 0, 'ai_reserved' => 0]);
        Redis::connection()->flushdb();
        Redis::connection('cache')->flushdb();
    }
}
