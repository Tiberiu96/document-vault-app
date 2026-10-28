<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminAccess;
use App\Services\Admin\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminImpersonationController extends Controller
{
    public function start(Request $request, string $client)
    {
        $request->validate(['confirm' => ['required', 'accepted'], 'current_password' => ['required', 'current_password:admin']]);
        abort_if($request->session()->has('admin.impersonation'), 409);
        $actor = Auth::guard('admin')->user();
        $target = DB::transaction(function () use ($client, $actor) {
            $target = User::where('is_super_admin', false)->lockForUpdate()->findOrFail($client);
            AdminAudit::record($actor, 'impersonation.started', 'user', $target->id);

            return $target;
        }, 3);
        // Sesiune noua, fara parola clientului si fara preluarea cookie-urilor sale.
        Auth::guard('web')->login($target, false);
        $request->session()->regenerate();
        $request->session()->forget(['active_workspace_id', 'password_hash_web']);
        $request->session()->put('active_workspace_id', $target->workspaces()->where('type', 'personal')->value('workspaces.id'));
        $request->session()->put('admin.impersonation', ['actor_id' => $actor->id, 'target_id' => $target->id, 'at' => now()->timestamp]);
        Auth::shouldUse('web');

        return redirect()->to(route('translated_ro.dashboard'));
    }

    public function stop(Request $request, AdminAccess $access)
    {
        $state = $request->session()->get('admin.impersonation');
        $actor = Auth::guard('admin')->user()?->fresh();
        if (is_array($state) && $actor && $actor->id === ($state['actor_id'] ?? null)) {
            AdminAudit::record($actor, 'impersonation.stopped', 'user', $state['target_id'] ?? null);
        }
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->forget(['admin.impersonation', 'active_workspace_id', 'password_hash_web']);
        $request->session()->regenerate();
        if (! $actor || ! $access->verified($request, $actor) || ! hash_equals(hash('sha256', $actor->password), (string) $request->session()->get('admin.password_fingerprint'))) {
            $access->logout($request);

            return redirect()->route('admin.login');
        }
        Auth::guard('admin')->setUser($actor);

        return redirect()->route('admin.users');
    }
}
