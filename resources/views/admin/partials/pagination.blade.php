@if($items->total() > 0)
<nav class="library-pagination" aria-label="{{ __('pagination.label') }}">
<span>{{ __('pagination.range', ['from'=>$items->firstItem() ?? 0, 'to'=>$items->lastItem() ?? 0, 'total'=>$items->total()]) }}</span>
@if($items->hasPages())
@if($items->previousPageUrl())<a class="library-small-button" rel="prev" href="{{ $items->previousPageUrl() }}">{{ __('pagination.previous') }}</a>@endif
<span>{{ $items->currentPage() }} / {{ $items->lastPage() }}</span>
@if($items->nextPageUrl())<a class="library-small-button" rel="next" href="{{ $items->nextPageUrl() }}">{{ __('pagination.next') }}</a>@endif
@endif
</nav>
@endif
