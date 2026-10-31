<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'requested_by', 'workspace_type', 'currency', 'plan_amount_minor', 'extra_seat_amount_minor', 'additional_member_seats', 'total_amount_minor', 'idempotency_key', 'provider_session_id', 'status', 'expires_at'])]
class BillingCheckoutAttempt extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['plan_amount_minor' => 'integer', 'extra_seat_amount_minor' => 'integer', 'additional_member_seats' => 'integer', 'total_amount_minor' => 'integer', 'expires_at' => 'immutable_datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
