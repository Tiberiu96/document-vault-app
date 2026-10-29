<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['*'])]
#[Hidden(['token_hash'])]
class WorkspaceInvitation extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['invited_by' => 'integer', 'accepted_by' => 'integer', 'expires_at' => 'datetime', 'last_sent_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function pending(): bool
    {
        return ! $this->accepted_at && ! $this->revoked_at && $this->expires_at->isFuture();
    }

    public function state(): string
    {
        return $this->accepted_at ? 'accepted' : ($this->revoked_at ? 'revoked' : ($this->expires_at->isPast() ? 'expired' : 'pending'));
    }
}
