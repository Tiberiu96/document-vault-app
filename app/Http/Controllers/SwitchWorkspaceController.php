<?php

namespace App\Http\Controllers;

use App\Http\Requests\SwitchWorkspaceRequest;
use Illuminate\Support\Facades\Gate;

class SwitchWorkspaceController extends Controller
{
    public function __invoke(SwitchWorkspaceRequest $request)
    {
        $workspace = $request->user()->workspaces()->whereKey($request->validated('workspace_id'))->firstOrFail();
        Gate::authorize('view', $workspace);
        $request->session()->put('active_workspace_id', $workspace->id);

        return redirect()->route('dashboard');
    }
}
