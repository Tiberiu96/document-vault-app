<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminAssistantUsageRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AdminAssistantUsageController extends Controller
{
    public function __invoke(AdminAssistantUsageRequest $request)
    {
        $filters = $request->validated();
        $query = DB::table('assistant_usage as usage');
        $this->applyFilters($query, $filters);

        $totals = (clone $query)->selectRaw(<<<'SQL'
            COUNT(*) as requests_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN status = 'interrupted' THEN 1 ELSE 0 END) as interrupted_count,
            SUM(CASE WHEN status = 'unknown' THEN 1 ELSE 0 END) as unknown_status_count,
            COUNT(input_tokens) as known_input_count,
            SUM(input_tokens) as input_tokens,
            COUNT(output_tokens) as known_output_count,
            SUM(output_tokens) as output_tokens,
            SUM(CASE WHEN input_tokens IS NOT NULL AND output_tokens IS NOT NULL THEN 1 ELSE 0 END) as known_total_count,
            SUM(CASE WHEN input_tokens IS NOT NULL AND output_tokens IS NOT NULL THEN input_tokens + output_tokens ELSE NULL END) as total_tokens
        SQL)->first();

        $usage = $query
            ->join('users', 'users.id', '=', 'usage.user_id')
            ->join('workspaces', 'workspaces.id', '=', 'usage.workspace_id')
            ->select([
                'usage.user_id', 'users.name as user_name', 'users.email as user_email',
                'usage.workspace_id', 'workspaces.name as workspace_name', 'usage.provider', 'usage.model',
            ])
            ->selectRaw(<<<'SQL'
                COUNT(*) as requests_count,
                SUM(CASE WHEN usage.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN usage.status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN usage.status = 'interrupted' THEN 1 ELSE 0 END) as interrupted_count,
                SUM(CASE WHEN usage.status = 'unknown' THEN 1 ELSE 0 END) as unknown_status_count,
                COUNT(usage.input_tokens) as known_input_count,
                SUM(usage.input_tokens) as input_tokens,
                COUNT(usage.output_tokens) as known_output_count,
                SUM(usage.output_tokens) as output_tokens,
                SUM(CASE WHEN usage.input_tokens IS NOT NULL AND usage.output_tokens IS NOT NULL THEN 1 ELSE 0 END) as known_total_count,
                SUM(CASE WHEN usage.input_tokens IS NOT NULL AND usage.output_tokens IS NOT NULL THEN usage.input_tokens + usage.output_tokens ELSE NULL END) as total_tokens,
                MAX(usage.created_at) as last_used_at
            SQL)
            ->groupBy([
                'usage.user_id', 'users.name', 'users.email', 'usage.workspace_id', 'workspaces.name',
                'usage.provider', 'usage.model',
            ])
            ->orderByDesc('last_used_at')
            ->orderBy('usage.user_id')
            ->orderBy('usage.workspace_id')
            ->orderBy('usage.provider')
            ->orderBy('usage.model')
            ->paginate(25, ['*'], 'usage_page')
            ->withQueryString();

        return view('admin.assistant-usage', compact('filters', 'totals', 'usage'));
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $timezone = config('vault.display_timezone');
        $query
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->where('usage.created_at', '>=', CarbonImmutable::parse($date, $timezone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->where('usage.created_at', '<', CarbonImmutable::parse($date, $timezone)->addDay()->startOfDay()->utc()))
            ->when($filters['user_id'] ?? null, fn (Builder $query, int $id) => $query->where('usage.user_id', $id))
            ->when($filters['workspace_id'] ?? null, fn (Builder $query, int $id) => $query->where('usage.workspace_id', $id));
    }
}
