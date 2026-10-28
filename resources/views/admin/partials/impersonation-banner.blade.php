@if(session()->has('admin.impersonation'))
<aside class="impersonation-banner" role="status"><span><strong>Mod asistenta · Numai consultare</strong> — {{ auth('web')->user()?->name }}</span><form method="POST" action="{{ route('admin.impersonation.stop') }}">@csrf<button type="submit" class="action-button">Incheie accesul la client</button></form></aside>
@endif
