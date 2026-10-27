<?php

namespace App\Http\Controllers;

use App\Models\Document;
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

        return view('account.dashboard', [
            ...app(WebsiteContext::class)->forPage($request, 'dashboard'),
            'workspaces' => $workspaces,
            'activeWorkspace' => $activeWorkspace, 'workspace' => $activeWorkspace, 'section' => 'dashboard',
            'recentDocuments' => $activeWorkspace ? Document::where('workspace_id', $activeWorkspace->id)->with('category')->latest()->limit(8)->get() : collect(),
            'documentCount' => $activeWorkspace ? Document::where('workspace_id', $activeWorkspace->id)->count() : 0,
            'pageCount' => $activeWorkspace ? Document::where('workspace_id', $activeWorkspace->id)->sum('page_count') : 0,
        ]);
    }
}
