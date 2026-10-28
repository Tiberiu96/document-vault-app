<?php

namespace App\Policies;

use App\Models\Reminder;
use App\Models\User;

class ReminderPolicy
{
    public function view(User $user, Reminder $reminder): bool
    {
        return $reminder->document && (new DocumentPolicy)->view($user, $reminder->document);
    }

    public function update(User $user, Reminder $reminder): bool
    {
        return $reminder->document && (new DocumentPolicy)->update($user, $reminder->document);
    }
}
