@extends('layouts.workspace')
@section('content')
<section class="library-panel team-invitation-card"><h1>{{ __('team.incoming_title') }}</h1><p>{{ __('team.wrong_account') }}</p><form method="POST" action="{{ route('logout') }}">@csrf<input type="hidden" name="switch_account" value="1"><button class="button button-outline" type="submit">{{ __('team.account_switch') }}</button></form></section>
@endsection
