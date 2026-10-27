@php
    $pending = in_array($document->ocr_status, ['queued', 'running']) || in_array($document->ai_status, ['queued', 'running']);
    $autoReview = request()->boolean('review') && $document->ai_requested && Gate::allows('update', $document);
@endphp
<section class="library-panel processing-panel" data-document-processing data-manual-url="{{ $autoReview ? route('documents.edit', ['document' => $document->id]) : '' }}" data-review-url="{{ $autoReview ? route('documents.suggestions', ['document' => $document->id]) : '' }}" data-checking="{{ __('processing.checking') }}" data-slow="{{ __('processing.slow') }}" data-offline="{{ __('processing.offline') }}" data-ocr-stage="{{ __('processing.ocr_stage') }}" data-ai-stage="{{ __('processing.ai_stage') }}" data-wait-stage="{{ __('processing.wait_stage') }}" data-status-url="{{ route('documents.processing-status', ['document' => $document->id]) }}" data-pending="{{ in_array($document->ocr_status, ['queued', 'running']) || in_array($document->ai_status, ['queued', 'running']) ? '1' : '0' }}">
<h2>{{ __('processing.title') }}</h2>
<dl><div><dt>{{ __('processing.ocr') }}</dt><dd data-ocr-state>{{ __('processing.states.'.$document->ocr_status) }}</dd></div><div><dt>{{ __('processing.ai') }}</dt><dd data-ai-state>{{ __('processing.states.'.$document->ai_status) }}</dd></div></dl>
@can('update', $document)
<div class="library-actions">@if(in_array($document->ocr_status, ['idle', 'failed', 'empty']))<form method="POST" action="{{ route('documents.process', ['document' => $document->id, 'kind' => 'ocr']) }}">@csrf<x-button type="submit" variant="outline">{{ __('processing.extract') }}</x-button></form>@endif
@if(config('ai.enabled') && $document->ocr_status === 'ready' && !in_array($document->ai_status, ['queued', 'running']))<form method="POST" action="{{ route('documents.process', ['document' => $document->id, 'kind' => 'ai']) }}">@csrf<x-button type="submit" variant="outline" icon="sparkle">{{ __('processing.analyze') }}</x-button></form>@endif</div>
@endcan
@if($pending)<div class="processing-progress" role="status" aria-live="polite"><span class="processing-spinner" aria-hidden="true"></span><div><strong data-processing-stage>{{ __('processing.checking') }}</strong><p data-processing-message>{{ __('processing.in_progress') }}</p><span data-processing-elapsed></span></div></div><a class="inline-link processing-refresh" href="{{ route('documents.show', ['document' => $document->id, ...($autoReview ? ['review' => 1] : [])]) }}">{{ __('documents.refresh') }}</a>@endif
@if(in_array($document->ocr_status, ['failed', 'empty']))<div class="form-alert">{{ __('processing.manual_review') }}</div>@endif
@if($document->ocr_text)<details class="ocr-text"><summary>{{ __('processing.extracted_text') }}</summary><pre>{{ $document->ocr_text }}</pre></details>@endif
@if($document->ai_status === 'needs_review')
<div class="form-alert" role="status"><strong>{{ __('processing.manual_review') }}</strong>@foreach($document->aiRun?->result['warnings'] ?? [] as $warning)<p>{{ __('processing.warnings.'.$warning) }}</p>@endforeach<p>{{ __('processing.no_autofill') }}</p></div>
@elseif($document->ai_status === 'ready')
@can('update', $document)<div class="security-notice"><p>{{ __('processing.ready') }}</p><a class="inline-link" href="{{ route('documents.suggestions', ['document' => $document->id]) }}">{{ __('processing.review') }}</a></div><form action="{{ route('documents.suggestions.reject', ['document' => $document->id]) }}" method="POST">@csrf<button type="submit" class="library-small-button">{{ __('processing.reject') }}</button></form>@endcan
@elseif($document->ai_status === 'failed')<div class="form-alert">{{ __('processing.failed') }}</div>@endif
</section>
