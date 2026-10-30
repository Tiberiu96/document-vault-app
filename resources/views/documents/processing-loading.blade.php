@php
    $record = $loadingDocument ?? null;
    $ocrDone = $record?->ocr_status === 'ready';
@endphp
<section class="processing-loading library-panel" data-processing-loading data-loading-ocr="{{ __('processing.ocr_stage') }}" data-loading-ai="{{ __('processing.ai_stage') }}" data-loading-wait="{{ __('processing.wait_stage') }}" @if($record) data-document-processing data-pending="1" data-status-url="{{ route('documents.processing-status', ['document' => $record->id]) }}" data-review-url="{{ request()->boolean('review') && $record->ai_requested && Gate::allows('update', $record) ? route('documents.suggestions', ['document' => $record->id]) : '' }}" data-manual-url="{{ request()->boolean('review') && Gate::allows('update', $record) ? route('documents.edit', ['document' => $record->id]) : '' }}" data-checking="{{ __('processing.loading_hint') }}" data-slow="{{ __('processing.slow') }}" data-offline="{{ __('processing.offline') }}" data-ocr-stage="{{ __('processing.ocr_stage') }}" data-ai-stage="{{ __('processing.ai_stage') }}" data-wait-stage="{{ __('processing.wait_stage') }}" @endif>
    <div class="processing-loading-grid">
        <div class="processing-loading-main">
            <span class="processing-kicker">{{ __('processing.title') }}</span>
            <h1>{{ __('processing.loading_title') }}</h1>
            <p class="processing-intro">{{ __('processing.loading_intro') }}</p>
            <ol class="processing-steps">
                <li class="is-complete"><span class="processing-step-node"><x-icon name="check" :size="20"/></span><strong>1. {{ __('processing.upload_step') }}</strong><span>{{ __('processing.upload_complete') }}</span></li>
                <li data-step-ocr @class(['is-current' => !$ocrDone, 'is-complete' => $ocrDone])><span class="processing-step-node"><x-icon name="check" :size="20"/></span><strong>2. OCR</strong><span>{{ __('processing.ocr_step_hint') }}</span></li>
                <li data-step-ai @class(['is-current' => $ocrDone])><span class="processing-step-node"><x-icon name="check" :size="20"/></span><strong>3. {{ __('processing.ai') }}</strong><span>{{ __('processing.ai_step_hint') }}</span></li>
            </ol>
            <div class="processing-meter"><div role="progressbar" aria-label="{{ __('processing.title') }}" aria-valuetext="{{ __('processing.in_progress') }}"><span></span></div><span class="processing-spinner" aria-hidden="true"></span></div>
            <div class="processing-activity" role="status" aria-live="polite"><x-icon name="sparkle" :size="28"/><div><strong data-processing-stage>{{ __($ocrDone ? 'processing.ai_stage' : 'processing.ocr_stage') }}</strong><p data-processing-message>{{ __('processing.loading_hint') }}</p></div><span class="processing-dots" aria-hidden="true"><i></i><i></i><i></i></span></div>
            <div class="processing-badges"><span class="is-complete"><x-icon name="circle-check" :size="17"/>{{ __('processing.upload_complete_short') }}</span><span data-badge-ocr>OCR: <span data-ocr-state>{{ $record ? __('processing.states.'.$record->ocr_status) : __('processing.states.queued') }}</span></span><span data-badge-ai><x-icon name="clock" :size="17"/>AI: <span data-ai-state>{{ $record ? __('processing.states.'.$record->aiDisplayState()) : __('processing.states.waiting_ocr') }}</span></span></div>
        </div>
        <aside class="processing-file">
            <div class="processing-file-card">
                <div class="review-source-thumb processing-file-preview"><x-icon name="document" :size="64"/><img data-local-image hidden alt="{{ __('documents.preview') }}"><canvas data-local-pdf hidden aria-label="{{ __('documents.preview') }}" role="img"></canvas>
                @if($record && !$record->source_released_at && ($record->mime_type !== 'application/pdf' || $record->preview_status === 'ready'))<img data-server-preview src="{{ route('documents.preview', ['document' => $record->id, ...($record->mime_type === 'application/pdf' ? ['page' => 1] : [])]) }}" alt="{{ __('documents.preview') }}">@endif
                </div>
                <div class="processing-file-info"><x-icon name="document" :size="28"/><div><strong data-flow-filename>{{ $record?->original_name ?? '' }}</strong><span data-flow-filesize>{{ $record ? number_format($record->size_bytes / 1048576, 2).' MB · '.($record->mime_type === 'application/pdf' ? 'PDF' : strtoupper(str_replace('image/', '', $record->mime_type))) : '' }}</span></div><a class="processing-preview-button" data-local-preview @if($record && !$record->source_released_at) href="{{ route('documents.download', ['document' => $record->id]) }}" @else hidden @endif target="_blank" rel="noopener" title="{{ __('documents.preview') }}" aria-label="{{ __('documents.preview') }}"><x-icon name="eye" :size="20"/></a></div>
            </div>
        </aside>
    </div>
    <footer class="processing-loading-footer"><div class="processing-time"><x-icon name="clock" :size="28"/><div><span>{{ __('processing.elapsed') }} <strong data-processing-elapsed>0:00</strong></span><p>{{ __('processing.in_progress') }}</p></div></div><div class="processing-footer-actions"><details class="processing-loading-details"><summary>{{ __('processing.view_details') }} <x-icon name="chevron" :size="16"/></summary><p>{{ __('processing.loading_hint') }}</p></details><button type="button" class="action-button" data-flow-retry hidden>{{ __('documents.refresh') }}</button>
    @if($record)
        @if($record->isDraft()) @can('update', $record)<form method="POST" action="{{ route('documents.discard', ['document' => $record->id]) }}">@csrf @method('DELETE')<button type="submit" class="processing-cancel"><x-icon name="close" :size="20"/>{{ __('documents.cancel') }}</button></form>@endcan @endif
    @else<button type="button" class="processing-cancel" data-flow-cancel><x-icon name="close" :size="20"/>{{ __('documents.cancel') }}</button>@endif
    </div></footer>
</section>
