<?php

namespace App\Services;

use App\Models\ProcessingUsage;
use App\Models\Workspace;
use App\Services\Documents\ProcessingQuotaExceeded;
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

    public function reserveProcessing(Workspace $lockedWorkspace, string $kind, int $units, string $period): void
    {
        if (! in_array($kind, ['ocr', 'ai'], true)) {
            throw new \InvalidArgumentException('Unsupported processing quota.');
        }
        $usage = ProcessingUsage::unguarded(fn () => ProcessingUsage::firstOrCreate(['workspace_id' => $lockedWorkspace->id, 'period' => $period]));
        $limit = $this->free()[$kind === 'ocr' ? 'monthly_ocr_pages' : 'monthly_ai_calls'];
        if ($limit >= 0 && $limit < $usage->{$kind.'_used'} + $usage->{$kind.'_reserved'} + $units) {
            throw ProcessingQuotaExceeded::withMessages(['processing' => __('processing.quota')]);
        }
        $usage->forceFill([$kind.'_reserved' => $usage->{$kind.'_reserved'} + $units])->save();
    }

    public function reserveStorage(Workspace $lockedWorkspace, int $bytes): void
    {
        if ($lockedWorkspace->storage_used_bytes + $bytes > $this->free()['storage_bytes']) {
            throw ValidationException::withMessages(['file' => __('documents.storage_full')]);
        }
        $lockedWorkspace->forceFill(['storage_used_bytes' => $lockedWorkspace->storage_used_bytes + $bytes])->save();
    }
}
