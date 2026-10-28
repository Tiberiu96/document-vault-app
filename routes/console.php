<?php

use App\Services\ReminderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('vault:reminders:dispatch', function () {
    $count = app(ReminderService::class)->dispatchDue();
    $this->info('Reminder deliveries dispatched: '.$count);
})->purpose('Dispatch due reminders without document content in output');

Schedule::command('vault:reminders:dispatch')->everyMinute()->withoutOverlapping(2);
