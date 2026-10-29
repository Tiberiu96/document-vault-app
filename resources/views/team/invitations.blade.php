@extends('layouts.workspace')
@section('content')
<div class="library-heading"><div><span class="eyebrow">{{ config('app.name') }}</span><h1>{{ __('team.incoming_title') }}</h1><p>{{ __('team.incoming_intro') }}</p></div></div>
<div class="team-incoming-list">@forelse($invitations as $invitation)@include('team.partials-invitation',['recipient'=>true])@empty<section class="library-panel team-empty"><x-icon name="bell" :size="32"/><h2>{{ __('team.empty') }}</h2></section>@endforelse</div>
@include('admin.partials.pagination',['items'=>$invitations])
@endsection
