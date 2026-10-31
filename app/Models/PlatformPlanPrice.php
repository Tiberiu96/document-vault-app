<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_type', 'currency', 'plan_amount_minor', 'extra_seat_amount_minor', 'enabled', 'version'])]
class PlatformPlanPrice extends Model
{
    protected function casts(): array
    {
        return ['plan_amount_minor' => 'integer', 'extra_seat_amount_minor' => 'integer', 'enabled' => 'boolean', 'version' => 'integer'];
    }
}
