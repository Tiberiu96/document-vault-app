@extends('layouts.admin')
@section('title','Mod consultare')
@section('content')<section class="library-panel admin-auth-card"><h1>Mod consultare client</h1><p>Modificarile sunt oprite cat timp consulti contul clientului. Incheie sesiunea de asistenta pentru a reveni la administrare.</p><form method="POST" action="{{ route('admin.impersonation.stop') }}">@csrf<x-button type="submit">Incheie accesul la client</x-button></form></section>@endsection
