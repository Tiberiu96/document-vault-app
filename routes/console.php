<?php

use App\Services\Backup\BackupBarrier;
use App\Services\ReminderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('vault:reminders:dispatch', function () {
    $barrier = app(BackupBarrier::class);
    $lock = $barrier->active() ? $barrier->shared() : null;
    if ($barrier->active() && ! $lock) {
        return;
    }
    try {
        $count = app(ReminderService::class)->dispatchDue();
    } finally {
        $barrier->release($lock);
    }
    $this->info('Reminder deliveries dispatched: '.$count);
})->purpose('Dispatch due reminders without document content in output');

Schedule::command('vault:reminders:dispatch')->everyMinute()->withoutOverlapping(2);

Schedule::call(fn () => Cache::put('vault:scheduler:last_seen', now()->timestamp, 180))->name('vault:scheduler:heartbeat')->everyMinute();
