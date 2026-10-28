<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BackupRunner
{
    public function run(): void
    {
        $repository = app(ResticRepository::class);
        $repository->configured();
        $state = app(BackupState::class);
        $lock = $state->runLock();
        if (! $lock) {
            throw new \RuntimeException('BACKUP_ALREADY_RUNNING');
        }
        $directory = $state->directory();
        $run = (string) Str::uuid();
        $stage = $directory.'/staging';
        $barrier = app(BackupBarrier::class);
        $exclusive = null;
        $oldUmask = umask(0077);
        try {
            if (is_dir($stage)) {
                File::deleteDirectory($stage);
            }
            mkdir($stage, 0700, true);
            $state->update(['run_id' => $run, 'status' => 'running', 'phase' => 'draining', 'started_at' => now()->timestamp, 'finished_at' => null, 'error_code' => null, 'warning_at' => null]);
            file_put_contents($directory.'/pending', $run);
            chmod($directory.'/pending', 0600);
            $exclusive = $barrier->exclusive(config('backup.drain_seconds'));
            $state->update(['phase' => 'snapshot']);
            $this->snapshot($stage);
            $barrier->release($exclusive);
            $exclusive = null;
            @unlink($directory.'/pending');
            $state->update(['phase' => 'upload']);
            $output = $repository->run(['backup', '--json', '--host', 'vaulty-production', '--tag', 'vaulty-candidate', '--tag', 'vaulty-run-'.$run, $stage]);
            $snapshot = null;
            $summary = [];
            foreach (explode("\n", $output) as $line) {
                $row = json_decode($line, true);
                if (($row['message_type'] ?? null) === 'summary') {
                    $snapshot = $row['snapshot_id'] ?? null;
                    $summary = $row;
                }
            }
            if (! is_string($snapshot) || ! preg_match('/\A[a-f0-9]{8,64}\z/', $snapshot)) {
                throw new \RuntimeException('BACKUP_SNAPSHOT_UNCONFIRMED');
            }
            $state->update(['phase' => 'verification']);
            $repository->run(['check']);
            $repository->run(['tag', '--add', 'vaulty-verified', '--remove', 'vaulty-candidate', $snapshot]);
            $verified = json_decode($repository->run(['snapshots', '--json', '--host', 'vaulty-production', '--tag', 'vaulty-verified,vaulty-run-'.$run]), true);
            if (! is_array($verified) || count($verified) !== 1 || ! preg_match('/\A[a-f0-9]{64}\z/', $verified[0]['id'] ?? '')) {
                throw new \RuntimeException('BACKUP_SNAPSHOT_UNCONFIRMED');
            }
            $snapshot = $verified[0]['id'];
            $state->update(['phase' => 'retention']);
            $cleanup = true;
            try {
                $repository->run(['forget', '--host', 'vaulty-production', '--tag', 'vaulty-verified', '--group-by', 'host', '--keep-last', '2', '--prune']);
            } catch (\Throwable) {
                $cleanup = false;
            }
            $current = $state->read();
            $state->update(['status' => $cleanup ? 'succeeded' : 'cleanup_warning', 'phase' => 'finished', 'last_success_at' => now()->timestamp, 'finished_at' => now()->timestamp, 'duration_seconds' => now()->timestamp - $current['started_at'], 'snapshot_id' => $snapshot, 'bytes_processed' => (int) ($summary['total_bytes_processed'] ?? 0), 'bytes_added' => (int) ($summary['data_added'] ?? 0), 'error_code' => $cleanup ? null : 'BACKUP_RETENTION_FAILED']);
        } catch (\Throwable $exception) {
            $current = $state->read();
            $allowed = ['BACKUP_DRAIN_TIMEOUT', 'BACKUP_SNAPSHOT_UNCONFIRMED', 'BACKUP_UNSAFE_SYMLINK', 'BACKUP_DISK_SPACE', 'BACKUP_DATABASE_UNSUPPORTED', 'BACKUP_TIME_LIMIT'];
            $state->update(['status' => 'failed', 'phase' => 'finished', 'finished_at' => now()->timestamp, 'duration_seconds' => now()->timestamp - ($current['started_at'] ?? now()->timestamp), 'error_code' => in_array($exception->getMessage(), $allowed, true) ? $exception->getMessage() : 'BACKUP_FAILED']);
            throw new \RuntimeException('BACKUP_FAILED');
        } finally {
            $barrier->release($exclusive);
            @unlink($directory.'/pending');
            if (is_dir($stage)) {
                File::deleteDirectory($stage);
            }
            $barrier->release($lock);
            umask($oldUmask);
        }
    }

    public function snapshot(string $stage): void
    {
        $connection = config('database.connections.'.config('database.default'));
        if (($connection['driver'] ?? null) !== 'mysql' || ! empty($connection['url'])) {
            throw new \RuntimeException('BACKUP_DATABASE_UNSUPPORTED');
        }
        $source = Storage::disk('local')->path('workspaces');
        if (is_link($source)) {
            throw new \RuntimeException('BACKUP_UNSAFE_SYMLINK');
        }
        $bytes = 0;
        $deadline = microtime(true) + config('backup.snapshot_seconds');
        if (is_dir($source)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
                if ($file->isLink()) {
                    throw new \RuntimeException('BACKUP_UNSAFE_SYMLINK');
                }
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('BACKUP_TIME_LIMIT');
                }
            }
        }
        $dbBytes = (int) (DB::selectOne('SELECT COALESCE(SUM(data_length + index_length),0) AS bytes FROM information_schema.tables WHERE table_schema = ?', [$connection['database']])->bytes ?? 0);
        $available = disk_free_space($stage);
        if ($available === false || $available < $bytes + 2 * $dbBytes + 268435456) {
            throw new \RuntimeException('BACKUP_DISK_SPACE');
        }
        $credentials = $stage.'/mysql.cnf';
        $quote = fn ($value) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $value).'"';
        $options = "[client]\n";
        foreach (['user' => 'username', 'password' => 'password', 'host' => 'host', 'port' => 'port', 'socket' => 'unix_socket'] as $key => $field) {
            if (($connection[$field] ?? '') !== '') {
                $options .= $key.'='.$quote($connection[$field])."\n";
            }
        }
        file_put_contents($credentials, $options);
        chmod($credentials, 0600);
        try {
            app(BackupProcess::class)->run([config('backup.mysqldump'), '--defaults-extra-file='.$credentials, '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--hex-blob', '--', $connection['database']], [], $this->remaining($deadline), $stage.'/database.sql');
        } finally {
            @unlink($credentials);
        }
        if (is_dir($source)) {
            mkdir($stage.'/documents', 0700);
            app(BackupProcess::class)->run(['cp', '--archive', '--reflink=auto', '--no-dereference', $source.'/.', $stage.'/documents'], [], $this->remaining($deadline));
        }
        file_put_contents($stage.'/recovery.json', json_encode(['app_key' => config('app.key'), 'app_name' => config('app.name'), 'captured_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR));
        chmod($stage.'/recovery.json', 0600);
    }

    private function remaining(float $deadline): int
    {
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 1) {
            throw new \RuntimeException('BACKUP_TIME_LIMIT');
        }

        return $remaining;
    }
}
