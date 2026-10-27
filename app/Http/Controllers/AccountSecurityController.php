<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\AccountMail;
use App\Services\WebsiteContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AccountSecurityController extends Controller
{
    private function page(Request $request, string $mode)
    {
        return view('auth.security', [...app(WebsiteContext::class)->forPage($request, 'security'), 'mode' => $mode]);
    }

    private function resendWait(Request $request): int
    {
        return max(
            RateLimiter::availableIn('verify-cooldown:'.$request->user()->id),
            RateLimiter::tooManyAttempts('verify-hour:'.$request->user()->id, 6) ? RateLimiter::availableIn('verify-hour:'.$request->user()->id) : 0,
        );
    }

    public function notice(Request $request)
    {
        return $request->user()->hasVerifiedEmail() ? redirect()->route('dashboard') : view('auth.security', [
            ...app(WebsiteContext::class)->forPage($request, 'security'), 'mode' => 'verify', 'resendAvailableIn' => $this->resendWait($request),
        ]);
    }

    public function verify(Request $request, string $id, string $hash)
    {
        $result = DB::transaction(function () use ($id, $hash) {
            $user = User::lockForUpdate()->findOrFail($id);
            if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
                return null;
            }
            $changed = ! $user->hasVerifiedEmail();
            if ($changed) {
                $user->markEmailAsVerified();
            }

            return [$user, $changed];
        });
        if (! $result) {
            return response()->view('auth.verification-error', [
                ...app(WebsiteContext::class)->forPage($request, 'security'), 'verificationProblem' => 'expired_link',
            ], 403);
        }
        [$user, $changed] = $result;
        if ($changed) {
            event(new Verified($user));
        }
        if ($request->user()?->id === $user->id) {
            return redirect()->route('dashboard')->with('security_status', __('account_mail.verified'));
        }

        return view('auth.security', [
            ...app(WebsiteContext::class)->forPage($request, 'security'), 'mode' => 'verified',
        ]);
    }

    public function resend(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard')->with('security_status', __('account_mail.verified'));
        }
        $lock = Cache::lock('verify-resend:'.$request->user()->id, 10);
        if (! $lock->get()) {
            return back()->with('security_status', __('account_mail.resend_wait', ['seconds' => 30]));
        }
        try {
            $wait = $this->resendWait($request);
            if ($wait > 0) {
                return response()->view('auth.security', [
                    ...app(WebsiteContext::class)->forPage($request, 'security'), 'mode' => 'verify',
                    'resendAvailableIn' => $wait, 'securityNotice' => $wait >= 60 ? __('account_mail.resend_hour_wait', ['minutes' => (int) ceil($wait / 60)]) : __('account_mail.resend_wait', ['seconds' => $wait]),
                ], 429, ['Retry-After' => $wait]);
            }
            $request->user()->sendEmailVerificationNotification();
            RateLimiter::hit('verify-cooldown:'.$request->user()->id, 30);
            RateLimiter::hit('verify-hour:'.$request->user()->id, 3600);
        } finally {
            $lock->release();
        }

        return redirect()->route('verification.notice')->with('security_status', __('account_mail.verification_sent'));
    }

    public function forgot(Request $request)
    {
        return $this->page($request, 'forgot');
    }

    private function email(Request $request): string
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        return mb_strtolower(trim($data['email']));
    }

    public function sendReset(Request $request)
    {
        $email = $this->email($request);
        Password::sendResetLink(['email' => $email]);

        // Acelasi raspuns pentru cont existent, necunoscut si broker throttled.
        return back()->with('security_status', __('account_mail.reset_sent'));
    }

    public function resetForm(Request $request)
    {
        return $this->page($request, 'reset');
    }

    public function reset(Request $request)
    {
        $email = $this->email($request);
        $data = $request->validate([
            'token' => ['required', 'string', 'max:256'],
            'password' => ['required', 'string', 'confirmed', 'max:72', PasswordRule::min(16)->mixedCase()->numbers()->symbols(), function ($attribute, $value, $fail) {
                if (is_string($value) && strlen($value) > 72) {
                    $fail(__('registration.password_long'));
                }
            }],
        ], ['password.*' => __('account_mail.password_rules')]);
        $status = Password::reset([...$data, 'email' => $email], function (User $user, string $password) use ($request) {
            DB::transaction(function () use ($user, $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                }
                event(new PasswordReset($user));
                $user->notify(new AccountMail('changed'));
            });
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        });
        if ($status !== Password::PasswordReset) {
            return back()->withErrors(['email' => __('account_mail.invalid_token')]);
        }

        return redirect()->route('login')->with('security_status', __('account_mail.reset_done'));
    }
}
