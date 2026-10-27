<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

class PlanLimitsService
{
    public function free(): array
    {
        // Niciun upgrade implicit pana la abonamentele verificate server-side.
        return config('vault.plans.free');
    }

    public function validateDocument(int $bytes, int $pages): void
    {
        if ($bytes > $this->free()['max_file_bytes'] || $pages > $this->free()['max_pages'] || $pages < 1) {
            throw ValidationException::withMessages(['file' => __('documents.limit')]);
        }
    }

    public function reserveStorage(Workspace $lockedWorkspace, int $bytes): void
    {
        if ($lockedWorkspace->storage_used_bytes + $bytes > $this->free()['storage_bytes']) {
            throw ValidationException::withMessages(['file' => __('documents.storage_full')]);
        }
        $lockedWorkspace->forceFill(['storage_used_bytes' => $lockedWorkspace->storage_used_bytes + $bytes])->save();
    }
}
