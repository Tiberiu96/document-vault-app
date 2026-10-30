@extends('layouts.workspace')
@section('content')
@php($isProcessing = !$document->trashed() && (in_array($document->ocr_status, ['queued', 'running']) || in_array($document->ai_status, ['queued', 'running']) || ($document->ai_requested && $document->ocr_status === 'ready' && $document->ai_status === 'idle')))
@if($isProcessing)
    @include('documents.processing-loading', ['loadingDocument' => $document])
@else
<a class="action-button" href="{{ route('documents.index') }}" data-action="back"><x-icon name="back" :size="18"/>{{ __('documents.back') }}</a>
<div class="library-heading"><div><span class="eyebrow">{{ $workspace->name }}</span><h1>{{ $document->title }}</h1>@if($document->summary)<p class="document-short-summary">{{ $document->summary }}</p>@endif<span class="stored-status"><i></i>{{ $document->isDraft() ? __('processing.draft') : __('documents.stored') }}</span></div><div class="library-actions">@unless($document->trashed())@unless($document->source_released_at)<x-button :href="route('documents.download', ['document' => $document->id])" variant="outline" icon="arrow">{{ __('documents.download') }}</x-button>@endunless @can('update', $document)<x-button :href="route('documents.edit', ['document' => $document->id])">{{ __('documents.edit') }}</x-button>@endcan @endunless</div></div>
@if($document->isDraft())<div class="security-notice"><p>{{ __('processing.draft_notice') }}</p><form method="POST" action="{{ route('documents.discard', ['document' => $document->id]) }}">@csrf @method('DELETE')<button type="submit" class="action-button action-button-danger">{{ __('documents.cancel') }}</button></form></div>@endif
<div class="document-detail-grid">
    <section class="library-panel document-preview"><div class="panel-heading"><h2>{{ __('documents.preview') }}</h2><span>{{ $document->page_count }} {{ trans_choice('documents.page_count', $document->page_count) }}</span></div>
        @if($document->source_released_at)<div class="library-empty"><x-icon name="shield" :size="40"/><p>{{ __('processing.source_erased') }}</p></div>
        @elseif($document->mime_type === 'application/pdf')
            @if($document->preview_status === 'ready')
                @for($pageNumber = 1; $pageNumber <= $document->page_count; $pageNumber++)<figure class="private-page"><img src="{{ route('documents.preview', ['document' => $document->id, 'page' => $pageNumber]) }}" alt="{{ __('documents.preview') }} {{ $pageNumber }}" class="private-image" loading="lazy"><figcaption>{{ $pageNumber }} / {{ $document->page_count }}</figcaption></figure>@endfor
            @else<div class="library-empty"><x-icon name="document" :size="40"/><p>{{ __('documents.'.($document->preview_status === 'failed' ? 'preview_failed' : 'preview_pending')) }}</p><a class="action-button" href="{{ route('documents.show', ['document' => $document->id]) }}">{{ __('documents.refresh') }}</a></div>@endif
        @else<img src="{{ route('documents.preview', ['document' => $document->id]) }}" alt="{{ $document->title }}" class="private-image">@endif
        <p class="library-footnote"><x-icon name="shield"/>{{ __('documents.original') }}</p>
    </section>
    <div><section class="library-panel document-metadata"><h2>{{ __('documents.details') }}</h2><dl>
        <div><dt>{{ __('documents.category_id') }}</dt><dd>{{ $document->category?->label() ?? __('documents.no_category') }}</dd></div>
        <div><dt>{{ __('documents.supplier') }}</dt><dd>{{ $document->supplier ?: '—' }}</dd></div>
        <div><dt>{{ __('documents.amount') }}</dt><dd>{{ $document->decimalAmount() !== null ? $document->decimalAmount().' '.$document->currency : '—' }}</dd></div>
        @foreach(['issued_on', 'expires_on'] as $date)<div><dt>{{ __('documents.'.$date) }}</dt><dd>{{ $document->$date?->format('d.m.Y') ?: '—' }}</dd></div>@endforeach
        <div><dt>{{ __('documents.size') }}</dt><dd>{{ number_format($document->size_bytes / 1024, 1) }} KB</dd></div><div><dt>{{ __('documents.added') }}</dt><dd>{{ $document->created_at->timezone(config('vault.display_timezone'))->format('d.m.Y H:i') }}</dd></div>
        <div><dt>{{ __('documents.tags') }}</dt><dd>@forelse($document->tags as $tag)<span class="document-chip">{{ $tag->label() }}</span>@empty — @endforelse</dd></div>
    </dl>@if($document->description)<p class="document-description">{{ $document->description }}</p>@endif</section>
    @unless($document->isDraft())
        @include('reminders.document', ['reminder' => $document->reminder])
        @can('delete', $document)<div class="library-danger"><a class="danger-button" href="{{ route('documents.delete-confirmation', ['document' => $document->id]) }}">{{ __('documents.delete') }}</a></div>@endcan
    @endunless
    </div>
</div>
@unless($document->trashed()) @include('documents.processing') @endunless
@endif
@endsection

@push('scripts')<script src="{{ asset('js/document-processing.js') }}?v={{ filemtime(public_path('js/document-processing.js')) }}" defer></script>@endpush
