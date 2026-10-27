@extends('layouts.workspace')
@section('content')
<a class="inline-link" href="{{ route('documents.index') }}">← {{ __('documents.back') }}</a>
<div class="library-heading"><div><span class="eyebrow">{{ $workspace->name }}</span><h1>{{ __('documents.upload') }}</h1><p>{{ __('processing.upload_first') }}</p></div></div>
<form class="library-panel library-form auth-form" method="POST" enctype="multipart/form-data" action="{{ route('documents.store') }}" data-document-upload>
    @csrf
    <label class="document-dropzone" id="document-dropzone"><x-icon name="upload" :size="36"/><strong>{{ __('documents.upload_hint') }}</strong><input type="file" id="document-file" name="file" accept="application/pdf,image/jpeg,image/png" required aria-label="{{ __('documents.choose') }}"><span id="selected-file" aria-live="polite"></span></label>
    <div class="camera-upload"><x-button :href="route('documents.scan')" variant="outline" icon="scan">{{ __('scanner.title') }}</x-button><p class="field-help">{{ __('scanner.intro') }}</p></div>
    @if(config('ai.enabled'))
    <div class="upload-ai-option"><input type="hidden" name="analyze_with_ai" value="0"><label class="remember"><input type="checkbox" name="analyze_with_ai" value="1" @checked(old('analyze_with_ai', '1') === '1')>{{ __('processing.auto_ai') }}</label><p class="field-help">{{ __(config('ai.provider') === 'groq' ? 'processing.remote_ai_hint' : 'processing.auto_ai_hint') }}</p></div>
    @endif
    <div class="upload-progress" role="status" hidden><span class="processing-spinner" aria-hidden="true"></span><span>{{ __('processing.uploading') }}</span></div>
    <p class="field-help">{{ __('documents.limit') }}</p>
    <div class="library-actions"><x-button type="submit" icon="upload">{{ __('processing.upload_continue') }}</x-button><a class="inline-link" href="{{ route('documents.index') }}">{{ __('documents.cancel') }}</a></div>
</form>
@endsection
@push('scripts')<script src="{{ asset('js/document-upload.js') }}?v={{ filemtime(public_path('js/document-upload.js')) }}" defer></script>@endpush
