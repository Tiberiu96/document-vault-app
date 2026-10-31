@props(['workspace' => null])
@auth
    @if(auth()->user()->hasVerifiedEmail() && config('assistant.enabled') && config('ai.enabled') && !session()->has('admin.impersonation'))
        @php($assistantWorkspace = $workspace ?? auth()->user()->workspaces()->find(session('active_workspace_id')))
        @if($assistantWorkspace && Gate::allows('view', $assistantWorkspace))
            @include('assistant.panel', ['workspace' => $assistantWorkspace])
            <script src="{{ asset('js/assistant.js') }}?v={{ filemtime(public_path('js/assistant.js')) }}" defer></script>
        @endif
    @endif
@endauth
