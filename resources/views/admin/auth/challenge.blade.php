@extends('layouts.admin')
@section('title','Confirma accesul')
@section('content')
<section class="library-panel admin-auth-card"><span class="eyebrow">Verificare suplimentara</span><h1>Confirma ca esti tu.</h1><p>Introdu un cod nou din aplicatia de autentificare sau un cod de recuperare nefolosit.</p><form class="auth-form" method="POST" action="{{ route('admin.mfa.verify') }}">@csrf<label for="mfa-code">Cod de autentificare sau recuperare</label><input id="mfa-code" name="code" autocomplete="one-time-code" maxlength="64" required spellcheck="false"><x-button type="submit">Confirma accesul</x-button></form></section>
@endsection
