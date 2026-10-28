<?php

return [
    'enabled' => (bool) env('REMINDERS_ENABLED', true),
    'email_enabled' => (bool) env('REMINDERS_EMAIL_ENABLED', false),
    'days_before' => [10, 3, 1],
    'hour' => 9,
    'changes_per_minute' => 15,
    'timezone' => 'Europe/Bucharest',
];
