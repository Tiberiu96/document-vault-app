{{ $brand }}

{{ __('account_mail.'.$kind.'_title') }}

{{ __('account_mail.greeting', ['name' => $name]) }}

{{ __('account_mail.'.$kind.'_intro') }}

{{ __('account_mail.'.$kind.'_action') }}:
{{ $actionUrl }}

@if($kind !== 'changed')
{{ __('account_mail.expiry', ['minutes' => $minutes]) }}
@endif

{{ __('account_mail.'.$kind.'_note') }}

{{ __('account_mail.footer') }}
{{ __('account_mail.automated') }}
