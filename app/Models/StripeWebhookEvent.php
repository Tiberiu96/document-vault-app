<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['environment', 'stripe_event_id', 'event_type', 'provider_created_at', 'payload', 'status'])]
class StripeWebhookEvent extends Model
{
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'provider_created_at' => 'integer', 'attempts' => 'integer', 'processed_at' => 'immutable_datetime'];
    }
}
