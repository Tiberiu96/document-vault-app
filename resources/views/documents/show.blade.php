@extends('layouts.workspace')
@section('content')
<a class="inline-link" href="{{ route('documents.index', $document->trashed() ? ['trash' => 1] : []) }}">← {{ __('documents.back') }}</a>
<div class="library-heading"><div><span class="eyebrow">{{ $workspace->name }}</span><h1>{{ $document->title }}</h1>@if($document->summary)<p class="document-short-summary">{{ $document->summary }}</p>@endif<span class="stored-status"><i></i>{{ $document->trashed() ? __('documents.trash') : __('documents.stored') }}</span></div><div class="library-actions">@unless($document->trashed())<x-button :href="route('documents.download', ['document' => $document->id])" variant="outline" icon="arrow">{{ __('documents.download') }}</x-button>@can('update', $document)<x-button :href="route('documents.edit', ['document' => $document->id])">{{ __('documents.edit') }}</x-button>@endcan @endunless</div></div>
@unless($document->trashed()) @include('documents.processing') @endunless
<div class="document-detail-grid">
    <section class="library-panel document-preview"><div class="panel-heading"><h2>{{ __('documents.preview') }}</h2><span>{{ $document->page_count }} {{ __('documents.pages') }}</span></div>
        @if($document->trashed())<div class="library-empty"><x-icon name="document" :size="48"/><p>{{ __('documents.trash_note') }}</p></div>
        @elseif($document->mime_type === 'application/pdf')
            @if($document->preview_status === 'ready')
                @for($pageNumber = 1; $pageNumber <= $document->page_count; $pageNumber++)<figure class="private-page"><img src="{{ route('documents.preview', ['document' => $document->id, 'page' => $pageNumber]) }}" alt="{{ __('documents.preview') }} {{ $pageNumber }}" class="private-image" loading="lazy"><figcaption>{{ $pageNumber }} / {{ $document->page_count }}</figcaption></figure>@endfor
            @else<div class="library-empty"><x-icon name="document" :size="40"/><p>{{ __('documents.'.($document->preview_status === 'failed' ? 'preview_failed' : 'preview_pending')) }}</p><a class="inline-link" href="{{ route('documents.show', ['document' => $document->id]) }}">{{ __('documents.refresh') }}</a></div>@endif
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
    @if($document->trashed())
        @if($document->purge_requested_at)<div class="security-notice">{{ __('documents.pending_purge') }}</div>
        @else
            @can('update', $document)<form method="POST" action="{{ route('documents.restore', ['document' => $document->id]) }}">@csrf<x-button type="submit" icon="check">{{ __('documents.restore') }}</x-button></form>@endcan
            @can('manage', [App\Models\Document::class, $workspace])<details class="library-danger"><summary>{{ __('documents.purge') }}</summary><p>{{ __('documents.purge_note') }}</p><form method="POST" action="{{ route('documents.purge', ['document' => $document->id]) }}">@csrf @method('DELETE')<label class="remember"><input type="checkbox" name="confirm" value="1" required>{{ __('documents.confirm_purge') }}</label><button type="submit" class="danger-button">{{ __('documents.purge') }}</button></form></details>@endcan
        @endif
    @else
        @can('delete', $document)<form method="POST" action="{{ route('documents.destroy', ['document' => $document->id]) }}" class="library-danger">@csrf @method('DELETE')<button type="submit" class="danger-button">{{ __('documents.move_trash') }}</button></form>@endcan
    @endif
    </div>
</div>
@endsection

@push('scripts')<script src="{{ asset('js/document-processing.js') }}" defer></script>@endpush
