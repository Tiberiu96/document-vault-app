<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'provider', 'environment', 'provider_customer_id', 'provider_subscription_id', 'status', 'currency', 'additional_member_seats', 'current_period_end', 'cancel_at_period_end', 'provider_event_created_at'])]
class WorkspaceBillingSubscription extends Model
{
    protected function casts(): array
    {
        return ['additional_member_seats' => 'integer', 'current_period_end' => 'immutable_datetime', 'cancel_at_period_end' => 'boolean', 'provider_event_created_at' => 'integer'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function grantsEntitlement(): bool
    {
        return in_array($this->status, ['active', 'trialing'], true);
    }
}
