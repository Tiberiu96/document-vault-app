@extends('layouts.public')
@section('content')
<div class="page-intro wrap"><span class="eyebrow">{{ config('app.name') }}</span><h1>{{ __('site.features.title') }}</h1><p>{{ __('site.features.status') }}</p><x-button :href="route('demo')" icon="arrow">{{ __('site.nav.demo') }}</x-button></div>
@include('partials.features')
@endsection
