@extends('layouts.admin')
@section('title','Planuri si limite')
@section('content')
<div class="admin-heading"><h1>Planuri si limite</h1><p>Cotele se aplica fiecarui workspace. Reducerea lor nu sterge documente si nu reseteaza consumul.</p></div>
<div class="library-actions">@foreach(['free','pro'] as $code)<a class="action-button" href="{{ route('admin.plans',['plan'=>$code]) }}" @if($plan === $code)aria-current="page"@endif>{{ ucfirst($code) }}</a>@endforeach</div>
<section class="library-panel admin-panel"><h2>Configurare {{ ucfirst($plan) }}</h2><form class="auth-form admin-form-grid" method="POST" action="{{ route('admin.plans.update') }}">@csrf @method('PATCH')<input type="hidden" name="version" value="{{ $version }}"><input type="hidden" name="plan" value="{{ $plan }}">
@foreach(['max_pages'=>'Pagini per document / scanare','max_file_bytes'=>'Marime maxima fisier (bytes)','storage_bytes'=>'Stocare per workspace (bytes)','monthly_ocr_pages'=>'Pagini OCR pe luna','monthly_ai_calls'=>'Analize AI pe luna'] as $key=>$label)<label>{{ $label }}<input type="number" name="{{ $key }}" value="{{ old($key, $limits[$key]) }}" min="{{ in_array($key,['monthly_ocr_pages','monthly_ai_calls']) && app()->environment('local') ? -1 : ($key === 'max_pages' ? 1 : 0) }}" required></label>@endforeach
@if($plan === 'pro')<label class="remember"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $enabled))>Permite atribuirea manuala Pro</label>@endif
<button class="library-small-button" type="submit">Salveaza planul</button></form><p class="field-help">Maximum 5 MiB per fisier in infrastructura actuala; marirea necesita configurare Nginx/PHP. Cotele lunare pot fi -1 numai local. Pro este un sablon, nu un abonament facturat. Daca este dezactivat sau expira, spatiul foloseste Free.</p></section>
@endsection
