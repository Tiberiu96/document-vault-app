@extends('layouts.workspace')
@section('content')
<a class="inline-link" href="{{ route('documents.create') }}">← {{ __('documents.back') }}</a>
<div class="library-heading"><div><span class="eyebrow">{{ $workspace->name }}</span><h1>{{ __('scanner.title') }}</h1><p>{{ __('scanner.intro') }}</p></div></div>
<section class="document-scanner" data-scanner data-max-pages="{{ $limits['max_pages'] }}" data-max-bytes="{{ $limits['max_file_bytes'] }}" data-failed="{{ __('scanner.failed') }}" data-invalid="{{ __('scanner.invalid') }}" data-limit="{{ __('scanner.limit') }}" data-fallback="{{ __('scanner.camera_fallback') }}" data-page-label="{{ __('scanner.pages') }}">
    <ol class="scan-steps"><li>1. {{ __('scanner.capture') }}</li><li>2. {{ __('scanner.adjust') }}</li><li>3. {{ __('scanner.confirm') }}</li></ol>
    <div class="scan-workbench">
        <div class="scan-viewer library-panel">
            <div class="scan-stage"><video data-scan-video autoplay playsinline muted hidden></video><canvas data-scan-canvas hidden aria-label="{{ __('scanner.adjust') }}"></canvas><div data-scan-empty class="scan-empty"><x-icon name="scan" :size="64"/><p>{{ __('scanner.guide') }}</p></div></div>
            <p class="scan-help">{{ __('scanner.manual') }}</p>
            <div class="library-actions"><x-button type="button" data-scan-camera icon="scan">{{ __('scanner.open_camera') }}</x-button><x-button type="button" data-scan-pick variant="outline" icon="upload">{{ __('scanner.choose') }}</x-button><x-button type="button" data-scan-shoot hidden icon="scan">{{ __('scanner.shoot') }}</x-button></div>
            <input type="file" data-scan-file accept="image/jpeg,image/png" multiple hidden>
            <input type="file" data-scan-camera-file accept="image/jpeg,image/png" capture="environment" hidden>
            <p data-scan-error class="form-alert" role="alert" hidden></p>
        </div>
        <aside class="library-panel scan-controls">
            <h2>{{ __('scanner.adjust') }}</h2><p>{{ __('scanner.select') }}</p>
            <div class="scan-crop-controls">@foreach(['left','right','top','bottom'] as $edge)<label>{{ __('scanner.'.$edge) }} <input type="range" min="0" max="40" value="0" data-crop="{{ $edge }}"></label>@endforeach</div>
            <div class="library-actions"><x-button type="button" data-scan-crop variant="outline">{{ __('scanner.crop') }}</x-button><x-button type="button" data-scan-rotate variant="outline">{{ __('scanner.rotate') }}</x-button><button type="button" data-scan-remove class="danger-button">{{ __('scanner.remove') }}</button></div>
            <h2>{{ __('scanner.pages') }} <span data-scan-count>0 / {{ $limits['max_pages'] }}</span></h2><div data-scan-pages class="scan-thumbnails"></div>
            <form data-scan-submit method="POST" action="{{ route('documents.scan-store') }}">@csrf
                @if(config('ai.enabled'))<label class="remember"><input type="checkbox" name="analyze_with_ai" value="1" checked>{{ __('processing.auto_ai') }}</label><p class="field-help">{{ __(config('ai.provider') === 'groq' ? 'processing.remote_ai_hint' : 'processing.auto_ai_hint') }}</p>@endif
                <p class="field-help">{{ __('documents.limit') }}</p><p class="field-help">{{ __('scanner.local_notice') }}</p>
                <div class="upload-progress" data-scan-loading role="status" hidden><span class="processing-spinner" aria-hidden="true"></span>{{ __('scanner.uploading') }}</div>
                <x-button type="submit" icon="check" disabled>{{ __('scanner.submit') }}</x-button>
            </form>
        </aside>
    </div>
    <noscript><div class="form-alert"><a href="{{ route('documents.create') }}">{{ __('documents.upload') }}</a></div></noscript>
</section>
@endsection
@push('scripts')<script src="{{ asset('js/document-scanner.js') }}?v={{ filemtime(public_path('js/document-scanner.js')) }}" defer></script>@endpush
