{{ $brand }}

{{ __('reminders.email_title') }}
{{ __('reminders.greeting', ['name' => $name]) }}
{{ __('reminders.email_intro', ['date' => $due]) }}
{{ $title }}

{{ $actionUrl }}#reminders

{{ __('reminders.email_note') }}
