<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_default' => 'boolean', 'translation_ready' => 'boolean'];
    }
}
