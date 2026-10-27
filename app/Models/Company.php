<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['legal_name', 'cui', 'registration_number', 'registered_address', 'country_code'])]
class Company extends Model
{
    protected function casts(): array
    {
        return ['registered_address' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
