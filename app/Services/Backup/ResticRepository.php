<?php

namespace App\Services\Backup;

class ResticRepository
{
    public function configured(): void
    {
        if (! app()->environment('production') || ! config('backup.enabled') || ! config('backup.single_host')) {
            throw new \RuntimeException('BACKUP_DISABLED');
        }
        if (config('database.default') !== 'mysql' || config('queue.default') !== 'redis') {
            throw new \RuntimeException('BACKUP_CONFIGURATION_INVALID');
        }
        foreach (['account_id', 'bucket', 'access_key', 'secret_key', 'password'] as $key) {
            if (! is_string(config('backup.'.$key)) || config('backup.'.$key) === '') {
                throw new \RuntimeException('BACKUP_CONFIGURATION_MISSING');
            }
        }
        if (! in_array(config('backup.jurisdiction'), ['default', 'eu'], true) || ! preg_match('/\A[a-f0-9]{32}\z/', config('backup.account_id')) || ! preg_match('/\A[a-z0-9][a-z0-9-]{1,61}[a-z0-9]\z/', config('backup.bucket')) || strlen(config('backup.password')) < 24 || config('backup.upload_kib') < 128 || config('backup.process_seconds') < 60 || config('backup.process_seconds') > 14400) {
            throw new \RuntimeException('BACKUP_CONFIGURATION_INVALID');
        }
    }

    public function run(array $arguments): string
    {
        $this->configured();

        return app(BackupProcess::class)->run([config('backup.restic'), '--limit-upload', (string) config('backup.upload_kib'), '-o', 's3.connections=2', ...$arguments], [
            'RESTIC_REPOSITORY' => 's3:https://'.config('backup.account_id').(config('backup.jurisdiction') === 'eu' ? '.eu' : '').'.r2.cloudflarestorage.com/'.config('backup.bucket').'/vaulty',
            'AWS_ACCESS_KEY_ID' => config('backup.access_key'), 'AWS_SECRET_ACCESS_KEY' => config('backup.secret_key'),
            'AWS_DEFAULT_REGION' => 'auto', 'RESTIC_PASSWORD' => config('backup.password'), 'RESTIC_CACHE_DIR' => app(BackupState::class)->directory().'/cache', 'GOMAXPROCS' => '1',
        ]);
    }
}
