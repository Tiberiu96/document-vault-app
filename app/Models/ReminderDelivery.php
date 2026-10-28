<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['*'])]
class ReminderDelivery extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['scheduled_for' => 'datetime', 'claimed_at' => 'datetime', 'in_app_at' => 'datetime', 'email_sent_at' => 'datetime', 'finished_at' => 'datetime', 'cycle' => 'integer'];
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }
}
