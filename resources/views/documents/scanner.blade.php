@extends('layouts.workspace')
@section('content')
<a class="action-button" href="{{ route('documents.create') }}" data-action="back"><x-icon name="back" :size="18"/>{{ __('documents.back') }}</a>
<div class="library-heading"><div><h1>{{ __('scanner.title') }}</h1><p>{{ __('scanner.compact_intro') }}</p></div></div>
<section class="document-scanner scan-compact" data-scanner data-max-pages="{{ $limits['max_pages'] }}" data-max-bytes="{{ $limits['max_file_bytes'] }}" data-failed="{{ __('scanner.failed') }}" data-invalid="{{ __('scanner.invalid') }}" data-limit="{{ __('scanner.limit') }}" data-fallback="{{ __('scanner.camera_fallback') }}" data-page-label="{{ __('scanner.pages') }}">
    <div class="scan-viewer library-panel">
        <div class="scan-stage"><video data-scan-video autoplay playsinline muted hidden></video><canvas data-scan-canvas hidden aria-label="{{ __('scanner.adjust') }}"></canvas><button type="button" data-scan-empty class="scan-empty"><x-icon name="scan" :size="48"/><strong>{{ __('scanner.tap_capture') }}</strong><span>{{ __('scanner.guide') }}</span></button>
            @foreach(['tl','tr','bl','br'] as $corner)<button type="button" class="scan-corner" data-crop-corner="{{ $corner }}" aria-label="{{ __('scanner.corner_'.$corner) }}" title="{{ __('scanner.corner_'.$corner) }}" hidden></button>@endforeach
        </div>
        <div class="scan-capture-actions library-actions"><x-button type="button" data-scan-camera icon="scan"><span class="scan-action-long">{{ __('scanner.open_camera') }}</span><span class="scan-action-short">{{ __('scanner.camera_short') }}</span></x-button><x-button type="button" data-scan-pick variant="outline" icon="upload"><span class="scan-action-long">{{ __('scanner.choose') }}</span><span class="scan-action-short">{{ __('scanner.gallery_short') }}</span></x-button><x-button type="button" data-scan-shoot hidden icon="scan">{{ __('scanner.shoot') }}</x-button></div>
        <input type="file" data-scan-file accept="image/jpeg,image/png" multiple hidden><input type="file" data-scan-camera-file accept="image/jpeg,image/png" capture="environment" hidden>
        <div data-scan-tools class="scan-edit-actions" hidden><x-button type="button" data-scan-crop variant="outline" icon="scan" aria-label="{{ __('scanner.crop') }}" title="{{ __('scanner.crop') }}"><span class="scan-tool-label">{{ __('scanner.crop') }}</span></x-button><x-button type="button" data-scan-rotate variant="outline" icon="rotate" aria-label="{{ __('scanner.rotate') }}" title="{{ __('scanner.rotate') }}"><span class="scan-tool-label">{{ __('scanner.rotate') }}</span></x-button><button type="button" data-scan-remove class="danger-button" aria-label="{{ __('scanner.remove') }}" title="{{ __('scanner.remove') }}"><x-icon name="trash"/><span class="scan-tool-label">{{ __('scanner.remove') }}</span></button></div>
        <p data-scan-error class="form-alert" role="alert" hidden></p>
        <div data-scan-page-section class="scan-page-section" hidden><p>{{ __('scanner.pages') }} <span data-scan-count>0 / {{ $limits['max_pages'] }}</span></p><div data-scan-pages class="scan-thumbnails"></div></div>
    </div>
    <form data-scan-submit method="POST" action="{{ route('documents.scan-store') }}" class="scan-confirm-form">@csrf
        @if(config('ai.enabled'))<label class="remember"><input type="checkbox" name="analyze_with_ai" value="1" checked>{{ __('processing.auto_ai') }}</label>@if(in_array(config('ai.provider'), ['groq', 'gemini'], true))<p class="field-help">{{ __('scanner.remote_notice') }}</p>@endif @endif
        <details class="scan-details"><summary>{{ __('scanner.details') }}</summary><p>{{ app(\App\Services\PlanLimitsService::class)->message($workspace) }}</p><p>{{ __('scanner.drag_hint') }}</p><p>{{ __('scanner.manual') }}</p></details>
        <div class="scan-continue"><div class="upload-progress" data-scan-loading role="status" hidden><span class="processing-spinner" aria-hidden="true"></span>{{ __('scanner.uploading') }}</div><x-button type="submit" icon="arrow" disabled>{{ __('scanner.continue') }}</x-button></div>
    </form>
    <noscript><div class="form-alert"><a href="{{ route('documents.create') }}">{{ __('documents.upload') }}</a></div></noscript>
</section>
@endsection
@push('scripts')<script src="{{ asset('js/document-scanner.js') }}?v={{ filemtime(public_path('js/document-scanner.js')) }}" defer></script>@endpush
