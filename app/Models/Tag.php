<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['*'])]
class Tag extends Model
{
    public function scopeForWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->where(fn ($q) => $q->where('workspace_id', $workspace->id)->orWhere(fn ($system) => $system->whereNull('workspace_id')->whereNotNull('system_key')));
    }

    public function label(): string
    {
        return $this->system_key ? __('documents.system_tags.'.$this->system_key) : $this->name;
    }
}
