@extends('layouts.workspace')
@section('content')
<section class="library-panel delete-confirmation" aria-labelledby="delete-title">
    <span class="delete-symbol"><x-icon name="trash" :size="28"/></span>
    <h1 id="delete-title">{{ __('documents.delete_question') }}</h1>
    <p class="delete-document-name">{{ $document->title }}</p>
    <p>{{ __('documents.delete_warning') }}</p>
    <div class="library-actions">
        <form method="POST" action="{{ route('documents.destroy', ['document' => $document->id]) }}">@csrf @method('DELETE')<button type="submit" name="confirm" value="1" class="danger-button">{{ __('documents.delete_yes') }}</button></form>
        <a class="inline-link" href="{{ route('documents.show', ['document' => $document->id]) }}">{{ __('documents.delete_no') }}</a>
    </div>
</section>
@endsection
