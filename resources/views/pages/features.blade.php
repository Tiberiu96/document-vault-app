@extends('layouts.public')
@section('content')
<div class="page-intro wrap"><span class="eyebrow">{{ config('app.name') }}</span><h1>{{ __('site.features.title') }}</h1><p>{{ __('site.features.status') }}</p><x-button :href="(auth()->check() ? route('dashboard') : route('register'))" icon="arrow">{{ (auth()->check() ? __('site.nav.account') : __('registration.create')) }}</x-button></div>
@include('partials.features')
@endsection
