<?php

namespace App\Services\Backup;

class BackupBarrier
{
    private array $jobLocks = [];

    private mixed $loopLock = null;

    public function active(): bool
    {
        return app()->environment('production') && config('backup.enabled') && config('backup.single_host');
    }

    public function shared(bool $blocking = false)
    {
        if (is_file(app(BackupState::class)->directory().'/restore/incomplete')) {
            return null;
        }
        $handle = fopen(app(BackupState::class)->directory().'/data.lock', 'c');
        if (! $handle || ! flock($handle, LOCK_SH | ($blocking ? 0 : LOCK_NB))) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return null;
        }

        return $handle;
    }

    public function release($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function loop(): bool
    {
        if (! $this->active()) {
            return true;
        }
        $this->release($this->loopLock);
        $this->loopLock = null;
        if (is_file(app(BackupState::class)->directory().'/pending')) {
            return false;
        }
        $this->loopLock = $this->shared();

        return is_resource($this->loopLock);
    }

    public function idle(): void
    {
        $this->release($this->loopLock);
        $this->loopLock = null;
    }

    public function jobStart(object $job): void
    {
        if (! $this->active()) {
            return;
        }
        // Workerul a luat deja lockul inainte de pop. Sync jobs au lockul cererii.
        $this->jobLocks[spl_object_id($job)] = $this->shared(true);
    }

    public function jobEnd(object $job): void
    {
        $key = spl_object_id($job);
        $this->release($this->jobLocks[$key] ?? null);
        unset($this->jobLocks[$key]);
        $this->release($this->loopLock);
        $this->loopLock = null;
    }

    public function exclusive(int $seconds)
    {
        $handle = fopen(app(BackupState::class)->directory().'/data.lock', 'c');
        if (! $handle) {
            throw new \RuntimeException('BACKUP_BARRIER_UNAVAILABLE');
        }
        $deadline = microtime(true) + $seconds;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }usleep(100000);
        } while (microtime(true) < $deadline);
        fclose($handle);
        throw new \RuntimeException('BACKUP_DRAIN_TIMEOUT');
    }
}
