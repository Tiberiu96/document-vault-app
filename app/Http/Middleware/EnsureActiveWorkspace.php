<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EnsureActiveWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        $workspace = $request->user()->workspaces()->find($request->session()->get('active_workspace_id'));
        abort_unless($workspace && Gate::allows('view', $workspace), 403);
        $request->attributes->set('workspace', $workspace);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
