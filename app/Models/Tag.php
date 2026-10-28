<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;

#[Guarded(['*'])]
class Tag extends Model
{
    protected function casts(): array
    {
        return ['translations' => 'array'];
    }

    public function scopeForWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->where(fn ($q) => $q->where('workspace_id', $workspace->id)->orWhere(fn ($system) => $system->whereNull('workspace_id')->whereNotNull('system_key')));
    }

    public function label(): string
    {
        if (! $this->system_key) {
            return $this->name;
        }
        if (isset($this->translations[app()->getLocale()])) {
            return $this->translations[app()->getLocale()];
        }
        $key = 'documents.system_tags.'.$this->system_key;

        return Lang::has($key) ? __($key) : ($this->name ?: $this->system_key);
    }
}
