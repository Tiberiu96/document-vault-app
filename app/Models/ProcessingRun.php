<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['*'])]
#[Hidden(['result'])]
class ProcessingRun extends Model
{
    use HasUuids;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    protected function casts(): array
    {
        return ['base_version' => 'integer', 'units' => 'integer', 'result' => 'encrypted:array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
