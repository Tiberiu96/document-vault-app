<?php

namespace App\Services;

use App\Models\User;

class NotificationPreferences
{
    public const DEFAULTS = ['reminders_in_app' => true, 'reminders_email' => true, 'processing_in_app' => true, 'usage_in_app' => true];

    public function forUser(User $user): array
    {
        $stored = $user->notification_preferences ?? [];
        $values = self::DEFAULTS;
        foreach ($values as $key => $default) {
            $values[$key] = is_bool($stored[$key] ?? null) ? $stored[$key] : $default;
        }

        return $values;
    }
}
