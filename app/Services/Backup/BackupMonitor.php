<?php

namespace App\Services\Backup;

use App\Notifications\BackupAlert;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

class BackupMonitor
{
    public function check(): void
    {
        if (! app()->environment('production') || ! config('backup.enabled')) {
            return;
        }
        $store = app(BackupState::class);
        $state = $store->read();
        $now = now()->timestamp;
        $lock = $store->runLock();
        $busy = ! is_resource($lock);
        if (is_resource($lock)) {
            try {
                $state = $store->read();
                if (is_file($store->directory().'/pending')) {
                    @unlink($store->directory().'/pending');
                }
                if (is_dir($store->directory().'/staging')) {
                    File::deleteDirectory($store->directory().'/staging');
                }
                if (($state['status'] ?? null) === 'running') {
                    $state = $store->update(['status' => 'interrupted', 'phase' => 'finished', 'finished_at' => $now, 'error_code' => 'BACKUP_INTERRUPTED']);
                }
            } finally {
                app(BackupBarrier::class)->release($lock);
            }
        }
        $reason = null;
        if ($busy && $now - ($state['started_at'] ?? $now) > config('backup.warning_seconds')) {
            $reason = 'slow';
        } elseif (in_array($state['status'] ?? null, ['failed', 'interrupted', 'cleanup_warning'], true)) {
            $reason = 'failed';
        } elseif (! $busy && $now - ($state['last_success_at'] ?? 0) > 27 * 3600) {
            $reason = 'stale';
        }
        $today = now('Europe/Bucharest')->setTime(23, 30);
        $expected = $today->isFuture() ? $today->subDay() : $today;
        if (! $busy && $now > $expected->timestamp + 15 * 60 && ($state['started_at'] ?? 0) < $expected->timestamp) {
            $reason = 'missing';
        }
        if (! $reason) {
            return;
        }
        $key = ($state['run_id'] ?? $expected->toDateString()).':'.$reason.':'.$expected->toDateString();
        if (($state['alert_key'] ?? null) === $key) {
            return;
        }
        $email = config('backup.alert_email');
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Notification::route('mail', $email)->notify(new BackupAlert($reason));
            $store->update(['alert_key' => $key, 'alert_at' => $now, 'alert_reason' => $reason]);
        } else {
            $store->update(['alert_reason' => $reason, 'monitor_error' => 'BACKUP_ALERT_EMAIL_MISSING']);
        }
    }
}
