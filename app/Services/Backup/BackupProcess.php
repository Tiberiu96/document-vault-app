<?php

namespace App\Services\Backup;

use Symfony\Component\Process\Process;

class BackupProcess
{
    public function run(array $arguments, array $environment = [], ?int $timeout = null, ?string $outputFile = null): string
    {
        if ($outputFile) {
            $position = array_search('--', $arguments, true);
            array_splice($arguments, $position === false ? count($arguments) : $position, 0, ['--result-file='.$outputFile]);
        }
        $state = app(BackupState::class)->read();
        $budget = config('backup.process_seconds');
        if (($state['status'] ?? null) === 'running') {
            $budget -= max(0, now()->timestamp - ($state['started_at'] ?? now()->timestamp));
        }
        if ($budget <= 0) {
            throw new \RuntimeException('BACKUP_TIME_LIMIT');
        }
        $timeout = min($timeout ?? $budget, $budget);
        $process = new Process(['nice', '-n', '15', 'ionice', '-c', '2', '-n', '7', ...$arguments], base_path(), $environment, null, $timeout ?? config('backup.process_seconds'));
        $output = '';
        $process->run(function ($type, $buffer) use (&$output, $process) {
            if ($type === Process::OUT) {
                $output = substr($output.$buffer, -1048576);
            }
            $process->clearOutput();
            $process->clearErrorOutput();
        });
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('BACKUP_PROCESS_FAILED');
        }

        return $output;
    }
}
