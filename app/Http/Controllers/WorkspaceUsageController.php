<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkspaceUpgradeRequest;
use App\Models\Workspace;
use App\Services\PlanLimitsService;
use App\Services\WebsiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WorkspaceUsageController extends Controller
{
    public function __invoke(Request $request, PlanLimitsService $limits)
    {
        $workspace = $request->attributes->get('workspace');
        Gate::authorize('view', $workspace);

        return view('account.usage', [...app(WebsiteContext::class)->forPage($request, 'dashboard'), 'section' => 'usage', 'workspace' => $workspace,
            'workspaces' => $request->user()->workspaces()->get()->filter(fn ($w) => Gate::allows('view', $w)), 'usage' => $limits->usage($workspace), 'upgradeRequest' => DB::table('workspace_upgrade_requests')->where('workspace_id', $workspace->id)->where('status', 'pending')->first()]);
    }

    public function requestUpgrade(WorkspaceUpgradeRequest $request, PlanLimitsService $limits)
    {
        DB::transaction(function () use ($request, $limits): void {
            $workspace = Workspace::lockForUpdate()->findOrFail($request->attributes->get('workspace')->id);
            Gate::authorize('requestUpgrade', $workspace);
            $additional = (int) $request->validated('additional_member_seats', 0);
            $current = $limits->resolve($workspace)['code'];
            if (($workspace->type !== 'business' && $additional > 0) || ($current === 'pro' && ($workspace->type !== 'business' || $additional <= $workspace->additional_member_seats))) {
                throw ValidationException::withMessages(['additional_member_seats' => __('plans.no_upgrade')]);
            }
            if (DB::table('workspace_upgrade_requests')->where('workspace_id', $workspace->id)->where('status', 'pending')->exists()) {
                return;
            }
            DB::table('workspace_upgrade_requests')->insert(['workspace_id' => $workspace->id, 'requested_by' => $request->user()->id,
                'additional_member_seats' => $additional, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        }, 3);

        return redirect()->route('usage')->with('library_status', __('plans.upgrade_requested'));
    }
}
