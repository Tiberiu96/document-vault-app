<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminLoginRequest;
use App\Http\Requests\Admin\AdminMfaRequest;
use App\Services\Admin\AdminAccess;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\AdminMfa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function login(Request $request, AdminAccess $access)
    {
        $user = Auth::guard('admin')->user();
        if ($user && $access->verified($request, $user)) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login');
    }

    public function authenticate(AdminLoginRequest $request)
    {
        if (! Auth::guard('admin')->attemptWhen($request->safe()->only(['email', 'password']), fn ($user) => $user->is_super_admin, false)) {
            return back()->withErrors(['email' => 'Date de autentificare incorecte.'])->withInput($request->only('email'));
        }
        $user = Auth::guard('admin')->user();
        $request->session()->regenerate();
        $request->session()->forget('admin');
        $request->session()->put('admin', ['password_at' => now()->timestamp, 'password_fingerprint' => hash('sha256', $user->password), 'last_seen' => now()->timestamp]);
        AdminAudit::record($user, 'auth.password_verified', 'user', $user->id);

        return redirect()->route($user->admin_mfa_confirmed_at ? 'admin.mfa.challenge' : 'admin.mfa.setup');
    }

    public function setup(Request $request, AdminMfa $mfa)
    {
        $user = Auth::guard('admin')->user();
        if ($user->admin_mfa_confirmed_at) {
            return redirect()->route('admin.mfa.challenge');
        }

        return view('admin.auth.setup', $mfa->begin($request, $user));
    }

    public function enroll(AdminMfaRequest $request, AdminMfa $mfa, AdminAccess $access)
    {
        $user = Auth::guard('admin')->user();
        $codes = $mfa->enroll($request, $user, $request->validated('code'));
        $access->markVerified($request, $user->refresh());
        $request->session()->flash('admin.new_recovery_codes', Crypt::encryptString(json_encode($codes, JSON_THROW_ON_ERROR)));

        return redirect()->route('admin.mfa.recovery');
    }

    public function challenge()
    {
        if (! Auth::guard('admin')->user()->admin_mfa_confirmed_at) {
            return redirect()->route('admin.mfa.setup');
        }

        return view('admin.auth.challenge');
    }

    public function verify(AdminMfaRequest $request, AdminMfa $mfa, AdminAccess $access)
    {
        $user = Auth::guard('admin')->user();
        if (! $mfa->verify($user, $request->validated('code'))) {
            throw ValidationException::withMessages(['code' => 'Cod invalid sau deja folosit.']);
        }
        $access->markVerified($request, $user->refresh());

        return redirect()->route('admin.dashboard');
    }

    public function recovery(Request $request)
    {
        $encrypted = $request->session()->pull('admin.new_recovery_codes');
        if (! is_string($encrypted)) {
            return redirect()->route('admin.security');
        }

        return view('admin.auth.recovery', ['codes' => json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR)]);
    }

    public function security()
    {
        return view('admin.security', ['adminUser' => Auth::guard('admin')->user()]);
    }

    public function regenerate(AdminMfaRequest $request, AdminMfa $mfa, AdminAccess $access)
    {
        $user = Auth::guard('admin')->user();
        if (! is_string($request->input('current_password')) || ! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Confirma parola curenta.']);
        }
        $codes = $mfa->regenerateRecovery($user, $request->validated('code'));
        $access->markVerified($request, $user->refresh());
        $request->session()->flash('admin.new_recovery_codes', Crypt::encryptString(json_encode($codes, JSON_THROW_ON_ERROR)));

        return redirect()->route('admin.mfa.recovery');
    }

    public function logout(Request $request, AdminAccess $access)
    {
        $user = Auth::guard('admin')->user();
        if ($user?->is_super_admin) {
            AdminAudit::record($user, 'auth.logout', 'user', $user->id);
        }
        $access->logout($request);

        return redirect()->route('admin.login');
    }
}
