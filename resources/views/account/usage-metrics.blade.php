<div class="usage-grid">
@foreach($usage['metrics'] as $kind => $metric)
<article class="library-panel usage-card"><span class="feature-icon"><x-icon :name="$kind === 'storage' ? 'database' : ($kind === 'ai' ? 'sparkle' : 'document')"/></span><h2>{{ __('plans.'.$kind) }}</h2>
@php($format = fn ($value) => $kind === 'storage' ? number_format($value / 1048576, 2).' MiB' : number_format($value, 0, ',', '.'))
<strong class="usage-value">{{ $format($metric['used']) }}<span> / {{ $metric['limit'] < 0 ? __('plans.unlimited') : $format($metric['limit']) }}</span></strong>
@if($metric['limit'] > 0)<progress value="{{ min($metric['used'] + $metric['reserved'], $metric['limit']) }}" max="{{ $metric['limit'] }}" aria-label="{{ __('plans.'.$kind) }}"></progress>@endif
<p>{{ __('plans.remaining') }}: {{ $metric['remaining'] === null ? __('plans.unlimited') : $format($metric['remaining']) }}</p>
@if($metric['reserved'])<p class="field-help">{{ __('plans.reserved', ['count'=>$format($metric['reserved'])]) }}</p>@endif
@if($metric['exceeded'])<p class="usage-warning">{{ __('plans.over_limit') }}</p>@elseif($metric['remaining'] === 0)<p class="usage-warning">{{ __('plans.reached') }}</p>@endif
</article>
@endforeach
</div>

@if($workspace->type === 'business')
<section class="library-panel usage-details"><h2>{{ __('team.seats_title') }}</h2><p>{{ __('team.seats_summary', ['members' => $usage['seats']['members'], 'pending' => $usage['seats']['pending'], 'limit' => $usage['seats']['limit']]) }}</p><p>{{ __('team.seats_included', ['included' => $usage['seats']['included'], 'additional' => $usage['seats']['additional']]) }}</p><p>{{ __('team.seats_help') }}</p></section>
@endif
