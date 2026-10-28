<section class="library-panel reminder-panel" id="reminders">
<div class="panel-heading"><h2>{{ __('reminders.title') }}</h2><a class="inline-link" href="{{ route('reminders.index') }}">{{ __('documents.view_all') }}</a></div>
<div class="reminder-panel-body">
@if(!config('reminders.enabled'))<p class="field-help">{{ __('reminders.paused') }}</p>@endif
@if($reminder)
    <div class="reminder-status-row"><span class="document-chip">{{ __('reminders.status_'.$reminder->status) }}</span><span>{{ __('reminders.due', ['date' => $reminder->due_on->format('d.m.Y')]) }}</span></div>
    <p class="field-help">{{ __('reminders.recipient', ['name' => $reminder->user->name]) }} · {{ config('reminders.email_enabled') ? __('reminders.email') : __('reminders.in_app') }}</p>
    @php($pending = $reminder->status === 'active' ? $reminder->deliveries->where('cycle', $reminder->cycle)->where('status', 'pending') : collect())
    <h3>{{ __('reminders.next') }}</h3>
    @if($pending->isEmpty())<p class="field-help">{{ __('reminders.none') }}</p>@else<ul class="reminder-timeline">@foreach($pending as $delivery)<li><time datetime="{{ $delivery->scheduled_for->toIso8601String() }}">{{ $delivery->scheduled_for->timezone(config('reminders.timezone'))->format('d.m.Y H:i') }}</time><span>{{ $delivery->days_before ? trans_choice('reminders.before', $delivery->days_before, ['days' => $delivery->days_before]) : __('reminders.snooze') }}</span></li>@endforeach</ul>@endif
    @can('update', $reminder)
    <div class="library-actions reminder-actions">
        @if($reminder->status === 'active')
        @foreach(['complete', 'disable'] as $action)<form method="POST" action="{{ route('reminders.update', ['reminder' => $reminder->id]) }}">@csrf @method('PATCH')<input type="hidden" name="cycle" value="{{ $reminder->cycle }}"><button class="library-small-button" name="action" value="{{ $action }}" type="submit">{{ __('reminders.'.$action) }}</button></form>@endforeach
        @endif
    </div>
    @foreach(['renew', 'snooze'] as $action)
        @if($action === 'renew' || ($reminder->status === 'active' && $reminder->due_on->toDateString() > now(config('reminders.timezone'))->addDay()->toDateString()))
        <details class="reminder-date-action"><summary>{{ __('reminders.'.$action) }}</summary><form method="POST" action="{{ route('reminders.update', ['reminder' => $reminder->id]) }}">@csrf @method('PATCH')<input type="hidden" name="cycle" value="{{ $reminder->cycle }}"><label>{{ __('reminders.'.($action === 'snooze' ? 'snooze_date' : 'date')) }}<input type="date" name="date" required min="{{ now(config('reminders.timezone'))->addDay()->toDateString() }}" @if($action === 'snooze')max="{{ $reminder->due_on->copy()->subDay()->toDateString() }}"@endif></label><button class="library-small-button" name="action" value="{{ $action }}" type="submit">{{ __('documents.save') }}</button></form></details>
        @endif
    @endforeach
    @endcan
    @if($reminder->deliveries->contains(fn ($d) => $d->status !== 'pending'))<details class="reminder-history"><summary>{{ __('reminders.history') }}</summary><ul class="reminder-timeline">@foreach($reminder->deliveries->where('status', '!=', 'pending')->sortByDesc('scheduled_for')->take(20) as $delivery)<li><time>{{ $delivery->scheduled_for->timezone(config('reminders.timezone'))->format('d.m.Y H:i') }}</time><span>{{ __('reminders.status_'.$delivery->status) }}@if($delivery->email_sent_at) · Email @elseif($delivery->in_app_at) · {{ __('reminders.in_app') }}@endif</span></li>@endforeach</ul></details>@endif
@else
    @if($document->expires_on)
        <p>{{ __('reminders.due', ['date' => $document->expires_on->format('d.m.Y')]) }}</p>
        @if($document->expires_on->toDateString() > now(config('reminders.timezone'))->toDateString())
            @can('update', $document)<form method="POST" action="{{ route('reminders.store', ['document' => $document->id]) }}">@csrf<input type="hidden" name="metadata_version" value="{{ $document->metadata_version }}"><button class="library-small-button" type="submit">{{ __('reminders.create') }}</button></form>@endcan
        @else
            <p class="field-help">{{ __('reminders.past_note') }}</p>
            @can('update', $document)<a class="inline-link" href="{{ route('documents.edit', ['document' => $document->id]) }}">{{ __('documents.edit') }}</a>@endcan
        @endif
    @else
        <p class="field-help">{{ __('reminders.no_date') }}</p>
        @can('update', $document)<form class="reminder-create" method="POST" action="{{ route('reminders.store', ['document' => $document->id]) }}">@csrf<input type="hidden" name="metadata_version" value="{{ $document->metadata_version }}"><label>{{ __('reminders.date') }}<input type="date" name="date" min="{{ now(config('reminders.timezone'))->addDay()->toDateString() }}" required></label><button class="library-small-button" type="submit">{{ __('reminders.create') }}</button></form>@endcan
    @endif
@endif
<p class="field-help">{{ __('reminders.schedule') }}</p>
</div></section>
