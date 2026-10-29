<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupMonitor;
use App\Services\Backup\BackupRestoreControl;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\ResticRepository;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RunBackup extends Command
{
    protected $signature = 'vault:backup {operation=run : run, init ou monitor}';

    protected $description = 'Backup productie, fara afisarea credentialelor sau a outputului proceselor.';

    public function handle(): int
    {
        if (! in_array($this->argument('operation'), ['run', 'init', 'monitor', 'restore', 'restore-validate', 'restore-apply', 'restore-finish'], true)) {
            $this->error('Operatie necunoscuta. Foloseste run, init, monitor sau restore.');

            return self::FAILURE;
        }
        if (! app()->environment('production') || ! config('backup.enabled')) {
            $this->error('Backupul este disponibil numai in productie, cu BACKUP_ENABLED=true.');

            return self::FAILURE;
        }
        try {
            match ($this->argument('operation')) {
                'restore' => $this->requestRestore(),
                'restore-validate' => app(BackupRestoreControl::class)->pending(),
                'restore-apply' => app(BackupRestorer::class)->apply(),
                'restore-finish' => $this->finishRestore(),
                'run' => app(BackupRunner::class)->run(),
                'init' => $this->initializeRepository(),
                'monitor' => app(BackupMonitor::class)->check(),
                default => throw new \RuntimeException('BACKUP_OPERATION_INVALID'),
            };
            $this->info('Operatie backup terminata. Verifica starea din administrare.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Operatie backup nereusita. Verifica starea din administrare. Daca ai pornit restaurarea, nu scoate mentenanta si nu reporni workerii pana la verificare.');

            return self::FAILURE;
        }
    }

    private function finishRestore(): void
    {
        $control = app(BackupRestoreControl::class);
        $state = $control->read('status');
        if (($state['phase'] ?? '') !== 'restored' || app()->isDownForMaintenance()) {
            throw new \RuntimeException('RESTORE_NOT_READY');
        }
        foreach (['php8.3-fpm.service', 'vaulty-horizon.service', 'vaulty-scheduler.service'] as $unit) {
            $process = new Process(['/usr/bin/systemctl', 'is-active', '--quiet', $unit]);
            $process->setTimeout(5);
            if ($process->run() !== 0) {
                throw new \RuntimeException('RESTORE_RESTART_FAILED');
            }
        }
        $control->write('status', [...$state, 'phase' => 'succeeded', 'finished_at' => now()->timestamp]);
    }

    private function requestRestore(): void
    {
        if (! $this->input->isInteractive() || ! config('backup.restore_enabled')) {
            throw new \RuntimeException('RESTORE_INTERACTIVE_REQUIRED');
        }
        $control = app(BackupRestoreControl::class);
        $snapshots = $control->snapshots();
        if (! $snapshots) {
            throw new \RuntimeException('RESTORE_SNAPSHOT_UNAVAILABLE');
        }
        $choices = array_map(fn ($row) => date('c', $row['at']).' | '.$row['id'], $snapshots);
        $selected = $this->choice('Alege copia verificata', $choices);
        $scope = $this->choice('Continut de restaurat', ['all', 'mysql', 'documents'], 'all');
        $this->warn('Restaurarea inlocuieste date. Sesiunile si joburile vechi sunt invalidate; reminderele cer verificare. Runnerul systemd trebuie instalat.');
        if ($this->ask('Scrie RESTAUREAZA pentru confirmare') !== 'RESTAUREAZA') {
            throw new \RuntimeException('RESTORE_CANCELLED');
        }
        $control->request($snapshots[array_search($selected, $choices, true)]['id'], $scope, null);
        $this->info('Cerere salvata. Runnerul separat va pune aplicatia in mentenanta si o va reporni numai dupa succes.');
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
