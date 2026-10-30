@props(['active' => false])
<a class="account-control" href="{{ route('profile.edit') }}" title="{{ __('site.nav.account') }}" aria-label="{{ __('site.nav.account') }}" @if($active) aria-current="page" @endif><x-user-avatar/></a>
