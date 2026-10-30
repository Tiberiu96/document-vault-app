<?php

namespace App\Http\Controllers;

use App\Actions\Account\UpdateProfile;
use App\Http\Requests\UpdateProfilePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Notifications\AccountMail;
use App\Services\WebsiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        $workspaces = $request->user()->workspaces()->get()->filter(fn ($workspace) => Gate::allows('view', $workspace));
        $workspace = $workspaces->firstWhere('id', $request->session()->get('active_workspace_id')) ?? $workspaces->first();

        return response()->view('account.profile', [
            ...app(WebsiteContext::class)->forPage($request, 'dashboard'),
            'section' => 'profile', 'workspace' => $workspace, 'workspaces' => $workspaces, 'user' => $request->user(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(UpdateProfileRequest $request, UpdateProfile $update)
    {
        $update->handle($request->user(), $request->validated('name'), $request->file('avatar'), $request->boolean('remove_avatar'));

        return redirect()->route('profile.edit')->with('library_status', __('profile.saved'));
    }

    public function avatar(Request $request)
    {
        // Fara ID controlat de client: avatarul propriu, privat, pe identitate.
        $path = $request->user()->fresh()?->avatar_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, 'avatar.jpg', [
            'Content-Type' => 'image/jpeg', 'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    public function password(UpdateProfilePasswordRequest $request)
    {
        DB::transaction(function () use ($request): void {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            if (! Hash::check($request->input('current_password'), $user->password)) {
                throw ValidationException::withMessages(['current_password' => __('auth.password_mismatch')]);
            }
            $user->forceFill(['password' => $request->validated('password'), 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
            $user->notify(new AccountMail('changed'));
        });
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('security_status', __('profile.password_saved'));
    }
}
