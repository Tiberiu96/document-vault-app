@props(['languages', 'workspace' => null, 'workspaces' => null])
@php
    $navigationUser = auth()->user();
    $workspaces ??= $navigationUser->workspaces()->get()->filter(fn ($space) => Gate::allows('view', $space));
    $workspace ??= $workspaces->firstWhere('id', request()->session()->get('active_workspace_id')) ?? $workspaces->first();
    if ($workspace) {
        request()->session()->put('active_workspace_id', $workspace->id);
    } else {
        request()->session()->forget('active_workspace_id');
    }
@endphp
<header class="member-header" data-member-header>
    <div class="member-header-bar wrap">
        <x-brand/>
        <div class="member-header-actions">
            @if($workspace && $navigationUser->hasVerifiedEmail())<livewire:notification-bell :workspace-id="$workspace->id" :key="'bell-'.$workspace->id"/>@endif
            <x-account-link :active="request()->routeIs('*.profile.edit')"/>
            <x-member-menu :languages="$languages" :workspace="$workspace" :workspaces="$workspaces"/>
        </div>
    </div>
</header>
@once
@push('scripts')<script src="{{ asset('js/member-navigation.js') }}?v={{ filemtime(public_path('js/member-navigation.js')) }}" defer></script>@endpush
@endonce
