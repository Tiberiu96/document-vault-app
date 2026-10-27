@props(['active' => false])
<a class="account-control" href="{{ route('dashboard') }}" title="{{ __('site.nav.account') }}" aria-label="{{ __('site.nav.account') }}" @if($active) aria-current="page" @endif><x-icon name="user" :size="21"/></a>
