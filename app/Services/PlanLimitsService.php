<?php

namespace App\Services;

use App\Models\ProcessingUsage;
use App\Models\Workspace;
use App\Services\Documents\ProcessingQuotaExceeded;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanLimitsService
{
    public function free(): array
    {
        // Niciun upgrade implicit pana la abonamentele verificate server-side.
        $defaults = config('vault.plans.free');
        $row = DB::table('platform_plan_limits')->where('plan', 'free')->first();
        if ($row) {
            foreach (array_keys($defaults) as $key) {
                $defaults[$key] = (int) $row->$key;
            }
        }
        if (! app()->environment('local')) {
            foreach (['monthly_ocr_pages', 'monthly_ai_calls'] as $key) {
                if ($defaults[$key] < 0) {
                    $defaults[$key] = max(0, (int) config('vault.plans.free.'.$key));
                }
            }
        }

        return $defaults;
    }

    public function message(): string
    {
        $limits = $this->free();

        return __('documents.limit', ['pages' => $limits['max_pages'], 'size' => rtrim(rtrim(number_format($limits['max_file_bytes'] / 1048576, 3, '.', ''), '0'), '.')]);
    }

    public function validateDocument(int $bytes, int $pages): void
    {
        if ($bytes > $this->free()['max_file_bytes'] || $pages > $this->free()['max_pages'] || $pages < 1) {
            throw ValidationException::withMessages(['file' => $this->message()]);
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
