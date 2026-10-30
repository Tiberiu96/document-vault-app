@extends('layouts.workspace')
@section('content')
<div class="library-heading"><div><span class="eyebrow">{{ config('app.name') }}</span><h1>{{ __('profile.title') }}</h1><p>{{ __('profile.intro') }}</p></div><a class="action-button" href="{{ route('dashboard') }}"><x-icon name="back"/>{{ __('documents.dashboard') }}</a></div>
<div class="profile-grid">
    <section class="library-panel profile-panel">
        <h2>{{ __('profile.details') }}</h2>
        <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="auth-form profile-form">
            @csrf @method('PATCH')
            <div class="profile-avatar-row"><x-user-avatar :user="$user" class="profile-avatar"/><div><label for="profile-avatar">{{ __('profile.avatar') }}</label><input id="profile-avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="avatar-help"><p id="avatar-help" class="field-help">{{ __('profile.avatar_rules') }}</p></div></div>
            @if($user->avatar_path)<label class="profile-remove"><input type="checkbox" name="remove_avatar" value="1">{{ __('profile.remove_avatar') }}</label>@endif
            <div><label for="profile-name">{{ __('profile.name') }}</label><input id="profile-name" name="name" value="{{ old('name', $user->name) }}" required minlength="2" maxlength="120" autocomplete="name">@error('name')<p class="form-error">{{ $message }}</p>@enderror</div>
            <div><label for="profile-email">{{ __('registration.email') }}</label><input id="profile-email" type="email" value="{{ $user->email }}" readonly aria-describedby="email-locked"><p id="email-locked" class="field-help">{{ __('profile.email_locked') }}</p></div>
            <x-button type="submit">{{ __('profile.save') }}</x-button>
        </form>
    </section>
    <section class="library-panel profile-panel">
        <h2 id="profile-password">{{ __('profile.password') }}</h2><p class="field-help">{{ __('profile.password_hint') }}</p>
        <form method="POST" action="{{ route('profile.password') }}" class="auth-form profile-form">
            @csrf @method('PUT')
            <div><label for="current-password">{{ __('profile.current_password') }}</label><input id="current-password" name="current_password" type="password" required autocomplete="current-password"></div>
            <div><label for="new-password">{{ __('profile.new_password') }}</label><input id="new-password" name="password" type="password" required minlength="16" maxlength="72" autocomplete="new-password"><p class="field-help">{{ __('account_mail.password_rules') }}</p></div>
            <div><label for="password-confirmation">{{ __('registration.password_confirmation') }}</label><input id="password-confirmation" name="password_confirmation" type="password" required minlength="16" maxlength="72" autocomplete="new-password"></div>
            <x-button type="submit" variant="outline">{{ __('profile.change_password') }}</x-button>
        </form>
    </section>
</div>
@endsection
