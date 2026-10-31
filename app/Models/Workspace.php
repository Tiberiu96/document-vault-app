<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name'])]
class Workspace extends Model
{
    protected function casts(): array
    {
        return ['plan_expires_at' => 'immutable_datetime', 'additional_member_seats' => 'integer', 'plan_version' => 'integer', 'storage_used_bytes' => 'integer'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')->withPivot('role')->withTimestamps();
    }

    public function company(): HasOne
    {
        return $this->hasOne(Company::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    public function billingSubscription(): HasOne
    {
        return $this->hasOne(WorkspaceBillingSubscription::class);
    }
}
