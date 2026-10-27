@extends('layouts.workspace')
@section('content')
<div class="library-heading"><div><span class="eyebrow">{{ __('ai.local_only') }}</span><h1>{{ __('ai.title') }}</h1><p>{{ __('ai.intro') }}</p></div></div>
<div class="security-notice"><x-icon name="shield"/><p>{{ __('ai.notice') }}</p></div>
<div class="library-panel library-form"><div class="panel-heading"><h2>{{ config('ai.ollama.model') }}</h2><span>{{ __('ai.queue') }}: ai</span></div>
    <form method="POST" action="{{ route('ai.run') }}" class="auth-form" data-ai-form>@csrf<label for="prompt">{{ __('ai.question') }}</label><textarea id="prompt" name="prompt" rows="4" maxlength="500" required placeholder="{{ __('ai.example') }}">{{ is_string(old('prompt')) ? old('prompt') : '' }}</textarea><p class="field-help">{{ __('ai.short_only') }}</p><x-button type="submit" icon="sparkle">{{ __('ai.send') }}</x-button></form>
</div>
@if($run)
<section class="library-panel ai-result" data-ai-result data-status-url="{{ route('ai.status', ['run' => $run]) }}" data-failure="{{ __('ai.failed') }}" aria-live="polite"><h2>{{ __('ai.answer') }}</h2><p data-ai-status>{{ __('ai.pending') }}</p><div data-ai-reply class="ai-reply" hidden></div></section>
@endif
@endsection
@push('scripts')<script src="{{ asset('js/local-ai.js') }}" defer></script>@endpush
