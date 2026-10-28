<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminAccess
{
    public function passwordFresh(Request $request): bool
    {
        $issued = (int) $request->session()->get('admin.password_at', 0);

        return $issued > 0 && $issued <= now()->timestamp && now()->timestamp - $issued <= config('admin.password_step_seconds');
    }

    public function verified(Request $request, User $user): bool
    {
        $proof = $request->session()->get('admin.mfa', []);

        return $user->is_super_admin && $user->admin_mfa_confirmed_at && $user->admin_mfa_secret
            && ($proof['user_id'] ?? null) === $user->id && ($proof['version'] ?? null) === $user->admin_mfa_version
            && ($proof['at'] ?? 0) > 0 && ($proof['at'] ?? 0) <= now()->timestamp
            && now()->timestamp - $proof['at'] <= config('admin.mfa_session_seconds');
    }

    public function markVerified(Request $request, User $user): void
    {
        $request->session()->regenerate();
        $request->session()->put('admin.mfa', ['user_id' => $user->id, 'version' => $user->admin_mfa_version, 'at' => now()->timestamp]);
        $request->session()->put('admin.last_seen', now()->timestamp);
        $request->session()->forget('admin.enrollment');
    }

    public function logout(Request $request): void
    {
        Auth::guard('admin')->logout();
        // Inchide si sesiunea web daca aceeasi sesiune de browser este partajata.
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
