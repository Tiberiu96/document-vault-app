<?php

namespace App\Http\Controllers;

use App\Services\WebsiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        return view('account.dashboard', [
            ...app(WebsiteContext::class)->forPage($request, 'dashboard'),
            'workspaces' => $request->user()->workspaces()->get()
                ->filter(fn ($workspace) => Gate::forUser($request->user())->allows('view', $workspace)),
        ]);
    }
}
