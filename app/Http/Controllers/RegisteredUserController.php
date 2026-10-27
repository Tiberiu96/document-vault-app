<?php

namespace App\Http\Controllers;

use App\Actions\Auth\RegisterUser;
use App\Http\Requests\RegisterRequest;
use App\Services\WebsiteContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RegisteredUserController extends Controller
{
    public function create(Request $request)
    {
        return view('auth.register', app(WebsiteContext::class)->forPage($request, 'register'));
    }

    public function store(RegisterRequest $request, RegisterUser $register)
    {
        try {
            $user = $register->execute($request->validated(), app()->getLocale());
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['email' => __('registration.email_unavailable')])
                ->withInput($request->safe()->except(['password']));
        }

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('active_workspace_id', $user->workspaces()
            ->where('type', $request->input('account_type') === 'pj' ? 'business' : 'personal')->value('workspaces.id'));

        return redirect()->route('dashboard');
    }
}
