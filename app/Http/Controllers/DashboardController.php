<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Reminder;
use App\Services\PlanLimitsService;
use App\Services\WebsiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $workspaces = $request->user()->workspaces()->get()
            ->filter(fn ($workspace) => Gate::forUser($request->user())->allows('view', $workspace));
        $activeWorkspace = $workspaces->firstWhere('id', $request->session()->get('active_workspace_id')) ?? $workspaces->first();
        if ($activeWorkspace) {
            $request->session()->put('active_workspace_id', $activeWorkspace->id);
        } else {
            $request->session()->forget('active_workspace_id');
        }
        $documentTotals = $activeWorkspace
            ? Document::where('workspace_id', $activeWorkspace->id)->where('status', 'stored')
                ->selectRaw('COUNT(*) as document_count, COALESCE(SUM(page_count), 0) as page_count')->first()
            : null;

        return view('account.dashboard', [
            ...app(WebsiteContext::class)->forPage($request, 'dashboard'),
            'workspaces' => $workspaces,
            'activeWorkspace' => $activeWorkspace, 'workspace' => $activeWorkspace, 'section' => 'dashboard',
            'usage' => $activeWorkspace ? app(PlanLimitsService::class)->usage($activeWorkspace) : null,
            'recentDocuments' => $activeWorkspace ? Document::where('workspace_id', $activeWorkspace->id)->where('status', 'stored')->with('category')->latest()->limit(8)->get() : collect(),
            'documentCount' => (int) ($documentTotals?->document_count ?? 0),
            'upcomingReminders' => $activeWorkspace ? Reminder::where('workspace_id', $activeWorkspace->id)->where('status', 'active')->whereHas('document', fn ($q) => $q->where('status', 'stored'))->whereHas('deliveries', fn ($q) => $q->where('status', 'pending')->where('scheduled_for', '>', now()))->with(['document', 'deliveries' => fn ($q) => $q->where('status', 'pending')->where('scheduled_for', '>', now())->orderBy('scheduled_for')])->orderBy('due_on')->limit(3)->get() : collect(),
            'pageCount' => (int) ($documentTotals?->page_count ?? 0),
        ]);
    }
}
