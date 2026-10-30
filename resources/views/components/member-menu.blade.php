@props(['languages', 'workspace' => null, 'workspaces'])
@php
    $baseRoute = Route::currentBaseName();
    $isCurrent = fn ($route) => $baseRoute === $route || match ($route) {
        'documents.index' => str_starts_with($baseRoute, 'documents.'),
        'categories.index' => str_starts_with($baseRoute, 'categories.') || str_starts_with($baseRoute, 'tags.'),
        'profile.edit' => str_starts_with($baseRoute, 'profile.'),
        'members.index' => str_starts_with($baseRoute, 'members.'),
        default => false,
    };
    $applicationLinks = ['dashboard' => ['home', __('documents.dashboard')]];
    if ($workspace && auth()->user()->hasVerifiedEmail()) {
        $applicationLinks += ['documents.index' => ['document', __('documents.title')], 'categories.index' => ['folder', __('documents.categories')],
            'reminders.index' => ['bell', __('reminders.title')], 'usage' => ['database', __('plans.title')], 'notifications.index' => ['bell', __('notifications.title')]];
        if (app()->environment('local') && config('ai.enabled') && Gate::allows('manage', [App\Models\Document::class, $workspace])) {
            $applicationLinks['ai.playground'] = ['sparkle', __('ai.title')];
        }
        if ($workspace->type === 'business') {
            $applicationLinks['members.index'] = ['user', __('team.title')];
        }
    }
@endphp
<details class="member-menu" data-member-menu>
    <summary aria-label="{{ __('site.nav.menu') }}" title="{{ __('site.nav.menu') }}"><x-icon name="menu" :size="23"/></summary>
    <div class="member-menu-panel">
        @if($workspace)<div class="member-menu-workspace"><x-workspace-picker :workspace="$workspace" :workspaces="$workspaces"/></div>@endif
        <nav class="member-menu-group" aria-label="{{ __('site.nav.application') }}">
            <h2>{{ __('site.nav.application') }}</h2>
            @foreach($applicationLinks as $routeName => [$icon, $label])<a href="{{ route($routeName) }}" @if($isCurrent($routeName)) aria-current="page" @endif><x-icon :name="$icon" :size="20"/>{{ $label }}</a>@endforeach
        </nav>
        <nav class="member-menu-group" aria-label="{{ __('site.nav.account') }}">
            <h2>{{ __('site.nav.account') }}</h2>
            <a href="{{ route('profile.edit') }}" @if($isCurrent('profile.edit')) aria-current="page" @endif><x-icon name="user"/>{{ __('profile.title') }}</a>
            @if(auth()->user()->hasVerifiedEmail())<a href="{{ route('invitations.index') }}" @if($isCurrent('invitations.index')) aria-current="page" @endif><x-icon name="folder"/>{{ __('team.incoming_title') }}</a>@endif
            <a href="{{ auth()->user()->hasVerifiedEmail() ? route('profile.edit').'#profile-password' : route('verification.notice') }}"><x-icon name="shield"/>{{ __('account_mail.status') }}</a>
        </nav>
        <nav class="member-menu-group" aria-label="{{ __('site.nav.discover') }}">
            <h2>{{ __('site.nav.discover') }}</h2>
            <a href="{{ route('home') }}" @if($isCurrent('home')) aria-current="page" @endif><x-icon name="home"/>{{ __('site.nav.public_home') }}</a>
            @foreach(['features','pricing','faq'] as $routeName)<a href="{{ route($routeName) }}" @if($isCurrent($routeName)) aria-current="page" @endif><x-icon name="{{ ['features'=>'sparkle','pricing'=>'database','faq'=>'info'][$routeName] }}"/>{{ __('site.nav.'.$routeName) }}</a>@endforeach
        </nav>
        <div class="member-menu-languages"><x-language-switcher :languages="$languages"/></div>
        <form method="POST" action="{{ route('logout') }}" class="member-menu-logout">@csrf<x-button type="submit" variant="outline">{{ __('site.nav.logout') }}</x-button></form>
    </div>
</details>
