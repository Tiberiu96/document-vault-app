@extends('layouts.workspace')
@section('content')
<a class="action-button" href="{{ route('invitations.index') }}"><x-icon name="back"/>{{ __('team.incoming_title') }}</a><div class="team-invitation-single">@include('team.partials-invitation')</div>
@endsection
