@extends('layouts.workspace')
@section('content')
<div class="library-heading"><div><span class="eyebrow">{{ __('documents.welcome', ['name' => auth()->user()->name]) }}</span><h1>{{ __('documents.title') }}</h1><p>{{ __('documents.intro') }}</p></div>@if($workspace)@can('create', [App\Models\Document::class, $workspace])<x-button :href="route('documents.create')" icon="upload">{{ __('documents.upload') }}</x-button>@endcan @endif</div>
@if($workspace)
<div class="library-stats"><article><span class="feature-icon"><x-icon name="document"/></span><strong>{{ $documentCount }}</strong><span>{{ __('documents.documents_count') }}</span></article><article><span class="feature-icon"><x-icon name="database"/></span><strong>{{ number_format($workspace->storage_used_bytes / 1048576, 2) }} MB</strong><span>{{ __('documents.storage') }}</span><progress value="{{ $workspace->storage_used_bytes }}" max="{{ config('vault.plans.free.storage_bytes') }}" aria-label="{{ __('documents.storage') }}"></progress></article><article><span class="feature-icon"><x-icon name="folder"/></span><strong>{{ $pageCount }}</strong><span>{{ __('documents.total_pages') }}</span></article><article class="library-plan"><span class="eyebrow">{{ __('documents.free_plan') }}</span><strong>{{ config('vault.plans.free.max_pages') }} {{ __('documents.pages') }}</strong><span>{{ __('documents.limit') }}</span></article></div>
<div class="library-panel"><div class="panel-heading"><h2>{{ __('documents.recent') }}</h2><a class="inline-link" href="{{ route('documents.index') }}">{{ __('documents.view_all') }}</a></div>@include('documents.table', ['documents' => $recentDocuments])</div>
<p class="library-footnote"><x-icon name="shield"/>{{ __('documents.ocr_note') }}</p>
@else
<div class="library-empty"><h2>{{ __('documents.no_workspace') }}</h2></div>
@endif
@endsection
