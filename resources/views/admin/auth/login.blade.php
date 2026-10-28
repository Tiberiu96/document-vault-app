@extends('layouts.admin')
@section('title','Autentificare administrare')
@section('content')
<section class="library-panel admin-auth-card"><span class="eyebrow">Acces restrictionat</span><h1>Administrare, in siguranta.</h1><p>Foloseste contul de administrator global. Dupa parola vei confirma accesul prin MFA.</p><form class="auth-form" method="POST" action="{{ route('admin.login.store') }}">@csrf<label for="admin-email">Email administrator</label><input id="admin-email" type="email" name="email" maxlength="255" value="{{ old('email') }}" autocomplete="username" required><label for="admin-password">Parola</label><input id="admin-password" type="password" name="password" autocomplete="current-password" required maxlength="256"><x-button type="submit" icon="arrow">Continua</x-button></form><a class="action-button" href="{{ route('root') }}" data-action="back"><x-icon name="back" :size="18"/>Inapoi la aplicatie</a></section>
@endsection
