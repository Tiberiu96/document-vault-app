@extends('layouts.workspace')
@section('content')
<div class="library-heading"><div><span class="eyebrow">{{ $workspace->name }}</span><h1>{{ __('documents.categories') }}</h1><p>{{ __('documents.taxonomy_hint') }}</p></div></div>
<div class="taxonomy-grid">
@foreach(['categories' => $categories, 'tags' => $tags] as $type => $items)
<section class="library-panel taxonomy-panel"><h2>{{ __('documents.'.($type === 'categories' ? 'category_id' : 'tags')) }}</h2>
@foreach($items as $item)
<div class="taxonomy-row">@if($item->system_key)<span>{{ $item->label() }}</span><span class="document-chip">{{ __('documents.system_label') }}</span>
@else
    @can('manage', [App\Models\Document::class, $workspace])
    <form method="POST" action="{{ route($type.'.update', ['item' => $item->id]) }}">@csrf @method('PATCH')<input name="name" aria-label="{{ __('documents.name') }}" value="{{ $item->name }}" maxlength="{{ $type === 'categories' ? 80 : 40 }}" required><button class="library-small-button" type="submit" aria-label="{{ __('documents.save') }}"><x-icon name="check"/></button></form><form method="POST" action="{{ route($type.'.destroy', ['item' => $item->id]) }}">@csrf @method('DELETE')<button class="danger-button" type="submit" aria-label="{{ __('documents.delete') }} {{ $item->name }}">{{ __('documents.delete') }}</button></form>
    @else<span>{{ $item->name }}</span>@endcan
@endif</div>
@endforeach
@can('manage', [App\Models\Document::class, $workspace])<form method="POST" action="{{ route($type.'.store') }}" class="taxonomy-create">@csrf<label for="new-{{ $type }}">{{ __('documents.'.($type === 'categories' ? 'new_category' : 'new_tag')) }}</label><div><input id="new-{{ $type }}" name="name" maxlength="{{ $type === 'categories' ? 80 : 40 }}" required><button class="library-small-button" type="submit">{{ __('documents.save') }}</button></div></form>@endcan
</section>
@endforeach
</div>
@endsection
