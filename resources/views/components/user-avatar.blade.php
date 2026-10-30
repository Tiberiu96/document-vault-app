@props(['user' => null])
@php($avatarUser = $user ?? auth()->user())
<span {{ $attributes->class(['user-avatar']) }} aria-hidden="true">@if($avatarUser?->avatar_path)<img src="{{ route('profile.avatar') }}" alt="" width="96" height="96">@else{{ mb_substr($avatarUser?->name ?? '', 0, 1) }}@endif</span>
