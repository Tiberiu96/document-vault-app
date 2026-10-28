@extends('layouts.admin')
@section('title','Coduri de recuperare')
@section('content')
<section class="library-panel admin-auth-card"><h1>Pastreaza codurile in siguranta.</h1><p>Fiecare cod poate fi folosit o singura data daca pierzi accesul la aplicatia de autentificare. Aceasta lista se afiseaza o singura data. Salveaz-o intr-un manager de parole; nu o trimite in chat.</p><div class="admin-recovery-codes">@foreach($codes as $code)<code>{{ $code }}</code>@endforeach</div><x-button :href="route('admin.dashboard')">Am salvat codurile</x-button></section>
@endsection
