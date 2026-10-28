@extends('layouts.workspace')
@section('content')
<a class="action-button" href="{{ route('documents.index') }}" data-action="back"><x-icon name="back" :size="18"/>{{ __('documents.back') }}</a>
<div class="library-heading upload-heading"><h1>{{ __('documents.upload') }}</h1><details class="upload-help"><summary aria-label="{{ __('processing.upload_help') }}" title="{{ __('processing.upload_help') }}"><x-icon name="info"/></summary><div>{{ __('documents.upload_hint') }}<br>{{ __('processing.auto_ai_hint') }}</div></details></div>
<form class="library-panel library-form auth-form clean-upload" method="POST" enctype="multipart/form-data" action="{{ route('documents.store') }}" data-document-upload data-failed="{{ __('processing.upload_failed') }}">
    @csrf
    <label class="document-dropzone" id="document-dropzone"><x-icon name="upload" :size="36"/><strong>{{ __('processing.choose_file') }}</strong><input class="sr-only" type="file" id="document-file" name="file" accept="application/pdf,image/jpeg,image/png" required aria-label="{{ __('documents.choose') }}"><span id="selected-file" aria-live="polite"></span></label>
    @if(config('ai.enabled'))
    <div class="upload-ai-option"><input type="hidden" name="analyze_with_ai" value="0"><label class="remember"><input type="checkbox" name="analyze_with_ai" value="1" @checked(old('analyze_with_ai', '1') === '1')>{{ __('processing.ai_upload_label') }}</label>@if(config('ai.provider') === 'groq')<p class="field-help">{{ __('scanner.remote_notice') }}</p>@endif</div>
    @endif
    <div class="upload-progress" role="status" hidden><span class="processing-spinner" aria-hidden="true"></span><span>{{ __('processing.uploading') }}</span></div>
    <div class="library-actions"><x-button type="submit" icon="arrow">{{ __('processing.upload_continue') }}</x-button><a class="action-button" href="{{ route('documents.index') }}">{{ __('documents.cancel') }}</a></div>
</form>
@endsection
@push('scripts')<script src="{{ asset('js/document-upload.js') }}?v={{ filemtime(public_path('js/document-upload.js')) }}" defer></script>@endpush
