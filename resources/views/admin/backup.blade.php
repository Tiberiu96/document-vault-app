@extends('layouts.admin')
@section('title','Backup')
@section('content')
@php
$labels = ['running'=>'In curs','succeeded'=>'Reusit','cleanup_warning'=>'Reusit, curatare de verificat','failed'=>'Esuat','interrupted'=>'Intrerupt','draining'=>'Asteapta operatiile active','snapshot'=>'Copie locala','upload'=>'Transfer criptat','verification'=>'Verificare','retention'=>'Curatare copii vechi','finished'=>'Incheiat','slow'=>'Durata depaseste 15 minute','missing'=>'Rularea programata lipseste','stale'=>'Nicio reusita recenta','restored'=>'Date aplicate, se verifica repornirea','queued'=>'Solicitata','downloading'=>'Descarcare si verificare','safety_copy'=>'Copie locala de siguranta','applying'=>'Aplicare','reconciling'=>'Invalidare sesiuni si joburi vechi'];
$mb = fn($value) => number_format($value / 1048576, 2, '.', ' ');
@endphp
<div class="admin-heading"><h1>Backup</h1><p>{{ $enabled ? 'Copii criptate pentru baza de date si documente.' : 'Backupul este oprit sau acest mediu nu este productie.' }}</p></div>
<section class="library-panel admin-panel">
    <h2>Ultima stare</h2>
    <dl class="admin-queue-list">
        @foreach(['status'=>'Stare','phase'=>'Etapa','started_at'=>'Pornit','finished_at'=>'Terminat','last_success_at'=>'Ultima reusita','duration_seconds'=>'Durata (secunde)','error_code'=>'Cod diagnostic','alert_reason'=>'Alerta','alert_at'=>'Data alertei','monitor_error'=>'Monitorizare'] as $key=>$label)
        <div><dt>{{ $label }}</dt><dd>@if(isset($state[$key])){{ str_ends_with($key,'_at') ? \Carbon\Carbon::createFromTimestamp($state[$key])->timezone('Europe/Bucharest')->format('d.m.Y H:i:s') : ($labels[$state[$key]] ?? $state[$key]) }}@else — @endif</dd></div>
        @endforeach
        <div><dt>Volum total al ultimului backup</dt><dd>{{ isset($state['bytes_processed']) ? $mb($state['bytes_processed']).' MB' : '—' }}</dd></div>
        <div><dt>Date noi identificate</dt><dd>{{ isset($state['bytes_added']) ? $mb($state['bytes_added']).' MB' : '—' }}</dd></div>
    </dl>
    <p class="field-help">Volumul total include toate datele din ultima copie. Datele noi reprezinta doar ce s-a adaugat dupa eliminarea duplicatelor, inainte de compresie; nu este cantitatea exacta trimisa prin internet. Valorile MB sunt afisate in MiB (1.048.576 bytes).</p>
    <p class="field-help">Programul prevazut este zilnic la 23:30, cu avertizare dupa 15 minute si ultimele doua copii verificate. Programarea necesita activarea timerelor pe server.</p>
</section>
<section class="library-panel admin-panel backup-section">
    <h2>Cloudflare R2 si copii disponibile</h2>
    <form method="POST" action="{{ route('admin.backup.inspect') }}">@csrf<button class="action-button" @disabled(!$enabled)>Actualizeaza detaliile</button></form>
    @if(!empty($inspection['at']))
    <p class="field-help">Masurat la {{ \Carbon\Carbon::createFromTimestamp($inspection['at'])->timezone('Europe/Bucharest')->format('d.m.Y H:i:s') }}. Actualizarea citeste obiectele prin API si consuma operatii R2.</p>
    <dl class="admin-queue-list">
        <div><dt>Spatiu total ocupat in Cloudflare{{ !($inspection['bucket']['complete'] ?? false) ? ' (masurare partiala)' : '' }}</dt><dd>{{ $mb($inspection['bucket']['bytes'] ?? 0) }} MB</dd></div>
        <div><dt>Obiecte numarate</dt><dd>{{ $inspection['bucket']['objects'] ?? 0 }}</dd></div>
    </dl>
    <p class="field-help">Tot spatiul ocupat acum de backupuri in Cloudflare, dupa compresie, inclusiv informatiile necesare restaurarii. Datele comune mai multor copii ocupa spatiu o singura data. Aceasta este ocuparea totala, nu dimensiunea ultimei copii si nici costul facturat.</p>
    @if(!($inspection['bucket']['complete'] ?? false))<p role="status">Lista a atins bugetul de timp sau paginare. Valoarea afisata este numai un minim, nu totalul bucketului.</p>@endif
    <div class="library-table-scroll"><table class="library-table"><thead><tr><th>Copie verificata</th><th>Identificator</th></tr></thead><tbody>
    @forelse($inspection['snapshots'] ?? [] as $snapshot)<tr><td>{{ \Carbon\Carbon::createFromTimestamp($snapshot['at'])->timezone('Europe/Bucharest')->format('d.m.Y H:i:s') }}</td><td>{{ substr($snapshot['id'], 0, 12) }}</td></tr>@empty<tr><td colspan="2">Nu sunt copii verificate disponibile.</td></tr>@endforelse
    </tbody></table></div>
    @else<p>Actualizeaza detaliile pentru a vedea spatiul ocupat si lista copiilor.</p>@endif
</section>
<section class="library-panel admin-panel backup-section backup-danger" @if(in_array($restoreState['phase'] ?? '', ['queued', 'downloading', 'safety_copy', 'applying', 'reconciling', 'restored']))data-restore-pending data-status-url="{{ route('admin.backup.restore.status') }}" data-login-url="{{ route('admin.login') }}"@endif>
    <h2>Restaureaza backup</h2>
    <p>Restaurarea inlocuieste datele selectate. Aplicatia intra temporar in mentenanta; reporneste automat numai dupa succes.</p>
    <p>Sesiunile si tokenurile sunt invalidate. Joburile vechi sunt eliminate, iar reminderele existente sunt oprite pentru verificare, ca sa nu retrimitem notificari deja livrate.</p>
    @if(!empty($restoreState['phase']))<p role="status">Ultima restaurare: <strong>{{ $labels[$restoreState['phase']] ?? 'De verificat' }}</strong>@if(!empty($restoreState['error_code'])) · {{ $restoreState['error_code'] }}@endif</p>@endif
    <p role="status" data-restore-progress></p>
    <button type="button" class="danger-button backup-restore-button" data-open-restore @disabled(!$enabled || !$restoreEnabled || empty($inspection['snapshots']))>Restaureaza backup</button>
    @if(!$restoreEnabled)<p class="field-help">Restaurarea este oprita pana la instalarea runnerului systemd si confirmarea bazelor Redis dedicate.</p>@endif
</section>
<dialog id="backup-restore-dialog" class="backup-dialog" aria-labelledby="restore-title">
    <form method="POST" action="{{ route('admin.backup.restore') }}" class="backup-restore-form">
        @csrf
        <h2 id="restore-title">Confirma restaurarea</h2>
        <label>Copie verificata<select name="snapshot" required>@foreach($inspection['snapshots'] ?? [] as $snapshot)<option value="{{ $snapshot['id'] }}">{{ \Carbon\Carbon::createFromTimestamp($snapshot['at'])->timezone('Europe/Bucharest')->format('d.m.Y H:i:s') }} · {{ substr($snapshot['id'],0,12) }}</option>@endforeach</select></label>
        <label>Ce restaurezi<select name="scope" required><option value="all">MySQL si documente</option><option value="mysql">Numai MySQL</option><option value="documents">Numai documente</option></select></label>
        <p class="field-help">Pentru a recupera un document sters din aplicatie alege MySQL si documente. Numai documente reface fisierele, fara sa recreeze inregistrari sterse. MySQL singur este refuzat daca originalele necesare lipsesc sau difera. Schema si APP_KEY trebuie sa corespunda instalarii curente.</p>
        <label>Parola administrator<input type="password" name="password" autocomplete="current-password" required></label>
        <label>Cod MFA nou, din aplicatia de autentificare<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label>
        <label>Scrie RESTAUREAZA<input name="confirmation" autocomplete="off" pattern="RESTAUREAZA" required></label>
        <label class="backup-confirm"><input type="checkbox" name="confirm" value="1" required> Confirm inlocuirea datelor si invalidarea sesiunilor, joburilor si notificarilor in asteptare.</label>
        <p class="field-help">Se pastreaza o copie locala privata a starii actuale. La esec, serviciile raman oprite pentru verificare; nu se reincearca automat.</p>
        <div class="backup-dialog-actions"><button type="button" class="action-button" data-close-restore>Renunta</button><button type="submit" class="danger-button backup-restore-button">Da, restaureaza backup</button></div>
    </form>
</dialog>
<script src="{{ asset('js/backup-admin.js') }}" defer></script>
@endsection
