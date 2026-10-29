<?php

namespace App\Services;

use App\Models\ProcessingUsage;
use App\Models\Workspace;
use App\Services\Documents\ProcessingQuotaExceeded;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanLimitsService
{
    public function definition(string $code): array
    {
        abort_unless(in_array($code, ['free', 'pro'], true), 404);
        $defaults = config('vault.plans.free');
        $row = DB::table('platform_plan_limits')->where('plan', $code)->first();
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

        return ['code' => $code, 'enabled' => $code === 'free' || ($row && (bool) $row->enabled), 'version' => (int) ($row->version ?? 0), 'limits' => $defaults];
    }

    public function free(): array
    {
        return $this->definition('free')['limits'];
    }

    public function resolve(?Workspace $workspace = null): array
    {
        if ($workspace && $workspace->plan_code === 'pro' && (! $workspace->plan_expires_at || $workspace->plan_expires_at->isFuture())) {
            $pro = $this->definition('pro');
            if ($pro['enabled']) {
                return $pro;
            }
        }

        return $this->definition('free');
    }

    public function forWorkspace(Workspace $workspace): array
    {
        return $this->resolve($workspace)['limits'];
    }

    public function message(?Workspace $workspace = null): string
    {
        $plan = $this->resolve($workspace);
        $limits = $plan['limits'];

        return __('plans.document_limit', ['plan' => ucfirst($plan['code']), 'pages' => $limits['max_pages'], 'size' => rtrim(rtrim(number_format($limits['max_file_bytes'] / 1048576, 3, '.', ''), '0'), '.')]);
    }

    public function validateDocument(int $bytes, int $pages, ?Workspace $workspace = null): void
    {
        $limits = $this->resolve($workspace)['limits'];
        if ($bytes > $limits['max_file_bytes'] || $pages > $limits['max_pages'] || $pages < 1) {
            throw ValidationException::withMessages(['file' => $this->message($workspace)]);
        }
    }

    public function reserveProcessing(Workspace $lockedWorkspace, string $kind, int $units, string $period): void
    {
        if (! in_array($kind, ['ocr', 'ai'], true) || $units < 1 || DB::transactionLevel() < 1) {
            throw new \InvalidArgumentException('Invalid processing quota reservation.');
        }
        $usage = ProcessingUsage::unguarded(fn () => ProcessingUsage::firstOrCreate(['workspace_id' => $lockedWorkspace->id, 'period' => $period]));
        $limit = $this->forWorkspace($lockedWorkspace)[$kind === 'ocr' ? 'monthly_ocr_pages' : 'monthly_ai_calls'];
        if ($limit >= 0 && $limit < $usage->{$kind.'_used'} + $usage->{$kind.'_reserved'} + $units) {
            throw ProcessingQuotaExceeded::withMessages(['processing' => __('processing.quota')]);
        }
        $usage->forceFill([$kind.'_reserved' => $usage->{$kind.'_reserved'} + $units])->save();
    }

    public function reserveStorage(Workspace $lockedWorkspace, int $bytes): void
    {
        if ($bytes < 0 || DB::transactionLevel() < 1) {
            throw new \InvalidArgumentException('Invalid storage quota reservation.');
        }
        if ($lockedWorkspace->storage_used_bytes + $bytes > $this->forWorkspace($lockedWorkspace)['storage_bytes']) {
            throw ValidationException::withMessages(['file' => __('documents.storage_full')]);
        }
        $lockedWorkspace->forceFill(['storage_used_bytes' => $lockedWorkspace->storage_used_bytes + $bytes])->save();
    }

    public function usage(Workspace $workspace): array
    {
        $plan = $this->resolve($workspace);
        $month = now('UTC')->startOfMonth();
        $usage = ProcessingUsage::where('workspace_id', $workspace->id)->where('period', $month->toDateString())->first();
        $metrics = [];
        foreach (['storage', 'ocr', 'ai'] as $kind) {
            $limit = $plan['limits'][match ($kind) {
                'storage' => 'storage_bytes', 'ocr' => 'monthly_ocr_pages', 'ai' => 'monthly_ai_calls'
            }];
            $used = (int) ($kind === 'storage' ? $workspace->storage_used_bytes : ($usage?->{$kind.'_used'} ?? 0));
            $reserved = $kind === 'storage' ? 0 : (int) ($usage?->{$kind.'_reserved'} ?? 0);
            $metrics[$kind] = ['used' => $used, 'reserved' => $reserved, 'limit' => $limit, 'remaining' => $limit < 0 ? null : max(0, $limit - $used - $reserved), 'exceeded' => $limit >= 0 && $used + $reserved > $limit];
        }

        return ['plan' => $plan['code'], 'limits' => $plan['limits'], 'metrics' => $metrics, 'period' => $month->format('Y-m'), 'resets_at' => $month->addMonth()->format('d.m.Y'), 'expires_at' => $plan['code'] === 'pro' ? $workspace->plan_expires_at : null];
    }
}
