<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Guarded(['*'])]
#[Hidden(['storage_path', 'sha256'])]
class Document extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date', 'purge_requested_at' => 'datetime', 'size_bytes' => 'integer', 'amount_minor' => 'integer'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function decimalAmount(): ?string
    {
        return $this->amount_minor === null ? null : intdiv($this->amount_minor, 100).'.'.str_pad((string) ($this->amount_minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
