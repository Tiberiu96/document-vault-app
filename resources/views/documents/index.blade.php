@extends('layouts.workspace')
@section('content')
<div class="library-heading"><div><span class="eyebrow">{{ $workspace->name }}</span><h1>{{ request()->boolean('trash') ? __('documents.trash') : __('documents.title') }}</h1><p>{{ request()->boolean('trash') ? __('documents.trash_note') : __('documents.intro') }}</p></div>@can('create', [App\Models\Document::class, $workspace])<x-button :href="route('documents.create')" icon="upload">{{ __('documents.upload') }}</x-button>@endcan</div>
<div class="library-tabs"><a href="{{ route('documents.index') }}" @if(!request()->boolean('trash')) aria-current="page" @endif>{{ __('documents.all') }}</a><a href="{{ route('documents.index', ['trash' => 1]) }}" @if(request()->boolean('trash')) aria-current="page" @endif>{{ __('documents.trash') }}</a><span>{{ $documents->total() }} {{ __('documents.documents_count') }}</span></div>
<form class="library-filters" method="GET" action="{{ route('documents.index') }}">
    @if(request()->boolean('trash'))<input type="hidden" name="trash" value="1">@endif
    <input name="q" type="search" maxlength="100" aria-label="{{ __('documents.search') }}" placeholder="{{ __('documents.search') }}" value="{{ $filters['q'] ?? '' }}">
    <select name="category" aria-label="{{ __('documents.category_id') }}"><option value="">{{ __('documents.all') }} · {{ __('documents.category_id') }}</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(($filters['category'] ?? '') == $category->id)>{{ $category->label() }}</option>@endforeach</select>
    <select name="tag" aria-label="{{ __('documents.tags') }}"><option value="">{{ __('documents.all') }} · {{ __('documents.tags') }}</option>@foreach($tags as $tag)<option value="{{ $tag->id }}" @selected(($filters['tag'] ?? '') == $tag->id)>{{ $tag->label() }}</option>@endforeach</select>
    <select name="sort" aria-label="{{ __('documents.filter') }}">@foreach(['newest', 'oldest', 'title'] as $sort)<option value="{{ $sort }}" @selected(($filters['sort'] ?? 'newest') === $sort)>{{ __('documents.'.($sort === 'title' ? 'by_title' : $sort)) }}</option>@endforeach</select>
    <button class="library-small-button" type="submit">{{ __('documents.filter') }}</button><a class="inline-link" href="{{ route('documents.index') }}">{{ __('documents.reset') }}</a>
</form>
<div class="library-panel">@include('documents.table')</div>
@if($documents->hasPages())<nav class="library-pagination" aria-label="{{ __('documents.view_all') }}">@if($documents->previousPageUrl())<a class="library-small-button" href="{{ $documents->previousPageUrl() }}">←</a>@endif<span>{{ $documents->currentPage() }} / {{ $documents->lastPage() }}</span>@if($documents->nextPageUrl())<a class="library-small-button" href="{{ $documents->nextPageUrl() }}">→</a>@endif</nav>@endif
@endsection
