@extends('layouts.admin')
@section('title', 'Consum asistent AI')
@section('content')
@php
    $tokenMetric = static function ($value, $known, $requests): array {
        $known = (int) $known;
        $requests = (int) $requests;
        if ($known === 0) {
            return ['Indisponibil', 'Consumul nu a fost raportat.'];
        }

        return [
            number_format((int) $value, 0, ',', '.'),
            $known === $requests ? 'Raportat pentru toate cererile.' : "Total partial: {$known} din {$requests} cereri.",
        ];
    };
    [$totalInput, $totalInputNote] = $tokenMetric($totals->input_tokens, $totals->known_input_count, $totals->requests_count);
    [$totalOutput, $totalOutputNote] = $tokenMetric($totals->output_tokens, $totals->known_output_count, $totals->requests_count);
    [$totalTokens, $totalTokensNote] = $tokenMetric($totals->total_tokens, $totals->known_total_count, $totals->requests_count);
@endphp
<div class="admin-heading">
    <span class="eyebrow">Operare asistent</span>
    <h1>Consum asistent AI</h1>
    <p>Agregari fara prompturi, conversatii, OCR, nume de fisiere sau costuri monetare.</p>
</div>

@include('admin.partials.filters', ['reset' => route('admin.assistant-usage'), 'fields' => [
    'from' => ['label' => 'De la', 'type' => 'date'],
    'to' => ['label' => 'Pana la', 'type' => 'date'],
    'user_id' => ['label' => 'ID utilizator', 'type' => 'number'],
    'workspace_id' => ['label' => 'ID spatiu', 'type' => 'number'],
]])

<div class="admin-stats admin-assistant-stats">
    <article class="library-panel"><span>Cereri inregistrate</span><strong>{{ number_format((int) $totals->requests_count, 0, ',', '.') }}</strong><small>Nu include cereri respinse inainte de pornirea fluxului.</small></article>
    <article class="library-panel"><span>Tokeni intrare</span><strong>{{ $totalInput }}</strong><small>{{ $totalInputNote }}</small></article>
    <article class="library-panel"><span>Tokeni iesire</span><strong>{{ $totalOutput }}</strong><small>{{ $totalOutputNote }}</small></article>
    <article class="library-panel"><span>Total tokeni</span><strong>{{ $totalTokens }}</strong><small>{{ $totalTokensNote }}</small></article>
</div>

<section class="library-panel admin-panel admin-usage-statuses" aria-label="Stari cereri">
    <h2>Starea cererilor inregistrate</h2>
    <dl>
        <div><dt>Finalizate</dt><dd>{{ $totals->completed_count }}</dd></div>
        <div><dt>Esuate</dt><dd>{{ $totals->failed_count }}</dd></div>
        <div><dt>Intrerupte</dt><dd>{{ $totals->interrupted_count }}</dd></div>
        <div><dt>Istoric fara stare</dt><dd>{{ $totals->unknown_status_count }}</dd></div>
    </dl>
</section>

<section class="library-panel admin-panel">
    <h2>Consum agregat</h2>
    <p>Fiecare rand grupeaza utilizatorul, spatiul, providerul si modelul efectiv raportate. Valorile istorice necunoscute raman indisponibile.</p>
    <div class="admin-table-scroll">
        <table class="admin-table admin-assistant-table">
            <thead><tr><th>Utilizator</th><th>Spatiu</th><th>Provider / model</th><th>Cereri</th><th>Tokeni</th><th>Ultima cerere</th></tr></thead>
            <tbody>
            @forelse($usage as $row)
                @php
                    [$input, $inputNote] = $tokenMetric($row->input_tokens, $row->known_input_count, $row->requests_count);
                    [$output, $outputNote] = $tokenMetric($row->output_tokens, $row->known_output_count, $row->requests_count);
                    [$total, $totalNote] = $tokenMetric($row->total_tokens, $row->known_total_count, $row->requests_count);
                @endphp
                <tr>
                    <td><strong>{{ $row->user_name }}</strong><span class="admin-cell-detail">ID {{ $row->user_id }} · {{ $row->user_email }}</span></td>
                    <td><strong>{{ $row->workspace_name }}</strong><span class="admin-cell-detail">ID {{ $row->workspace_id }}</span></td>
                    <td><strong>{{ $row->provider ?: 'Indisponibil' }}</strong><span class="admin-cell-detail">{{ $row->model ?: 'Model indisponibil' }}</span></td>
                    <td><strong>{{ $row->requests_count }} inregistrate</strong><span class="admin-cell-detail">{{ $row->completed_count }} finalizate · {{ $row->failed_count }} esuate · {{ $row->interrupted_count }} intrerupte · {{ $row->unknown_status_count }} fara stare</span></td>
                    <td>
                        <span>Intrare: <strong>{{ $input }}</strong></span><span class="admin-cell-detail">{{ $inputNote }}</span>
                        <span>Iesire: <strong>{{ $output }}</strong></span><span class="admin-cell-detail">{{ $outputNote }}</span>
                        <span>Total: <strong>{{ $total }}</strong></span><span class="admin-cell-detail">{{ $totalNote }}</span>
                    </td>
                    <td>{{ \Carbon\Carbon::parse($row->last_used_at)->timezone(config('vault.display_timezone'))->format('d.m.Y H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="6">Nu exista consum inregistrat pentru filtrele selectate.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('admin.partials.pagination', ['items' => $usage])
</section>
@endsection
