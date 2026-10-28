<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupMonitor;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\ResticRepository;
use Illuminate\Console\Command;

class RunBackup extends Command
{
    protected $signature = 'vault:backup {operation=run : run, init ou monitor}';

    protected $description = 'Backup productie, fara afisarea credentialelor sau a outputului proceselor.';

    public function handle(): int
    {
        if (! app()->environment('production') || ! config('backup.enabled')) {
            $this->error('Backupul este disponibil numai in productie, cu BACKUP_ENABLED=true.');

            return self::FAILURE;
        }
        try {
            match ($this->argument('operation')) {
                'run' => app(BackupRunner::class)->run(),
                'init' => $this->initializeRepository(),
                'monitor' => app(BackupMonitor::class)->check(),
                default => throw new \RuntimeException('BACKUP_OPERATION_INVALID'),
            };
            $this->info('Operatie backup terminata. Verifica starea din administrare.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Operatie backup nereusita. Verifica configuratia si codul sanitizat din administrare.');

            return self::FAILURE;
        }
    }

    private function initializeRepository(): void
    {
        $repository = app(ResticRepository::class);
        $repository->configured();
        if (! $this->input->isInteractive() || ! $this->confirm('Initializezi un repository Restic nou in bucketul dedicat?', false)) {
            throw new \RuntimeException('BACKUP_INIT_CANCELLED');
        }
        $repository->run(['init']);
    }
}
