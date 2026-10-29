<?php

namespace App\Http\Controllers;

use App\Services\PlanLimitsService;
use App\Services\WebsiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkspaceUsageController extends Controller
{
    public function __invoke(Request $request, PlanLimitsService $limits)
    {
        $workspace = $request->attributes->get('workspace');
        Gate::authorize('view', $workspace);

        return view('account.usage', [...app(WebsiteContext::class)->forPage($request, 'dashboard'), 'section' => 'usage', 'workspace' => $workspace,
            'workspaces' => $request->user()->workspaces()->get()->filter(fn ($w) => Gate::allows('view', $w)), 'usage' => $limits->usage($workspace)]);
    }
}
