@extends('layouts.admin')
@section('title','Prea multe incercari')
@section('content')<section class="library-panel admin-auth-card"><h1>Mai asteapta putin.</h1><p>Prea multe incercari. Poti incerca din nou peste {{ $seconds }} secunde.</p><a class="action-button" href="{{ route('admin.login') }}">Autentificare</a></section>@endsection
