<form class="admin-filters library-panel" method="GET" action="{{ $reset }}">
@foreach($fields as $name => $field)
<label for="admin-filter-{{ $name }}">{{ $field['label'] }}
@if(isset($field['options']))<select id="admin-filter-{{ $name }}" name="{{ $name }}"><option value="">Toate</option>@foreach($field['options'] as $value => $label)<option value="{{ $value }}" @selected((string)($filters[$name] ?? '') === (string)$value)>{{ $label }}</option>@endforeach</select>
@else<input id="admin-filter-{{ $name }}" name="{{ $name }}" type="{{ $field['type'] ?? 'search' }}" value="{{ $filters[$name] ?? '' }}" @if(($field['type'] ?? '') === 'number')min="1"@else maxlength="100"@endif>
@endif</label>
@endforeach
<div class="admin-filter-actions"><button class="library-small-button" type="submit">Filtreaza</button><a class="action-button" href="{{ $reset }}">Reseteaza</a></div>
</form>
