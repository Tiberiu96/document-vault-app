<?php

namespace App\Http\Middleware;

use App\Services\Backup\BackupBarrier;
use Closure;
use Illuminate\Http\Request;

class BackupSnapshotBarrier
{
    public function handle(Request $request, Closure $next)
    {
        $barrier = app(BackupBarrier::class);
        if (! $barrier->active()) {
            return $next($request);
        }
        $lock = $barrier->shared();
        if (! $lock) {
            return response('Mentenanta temporara pentru copia de siguranta. Reincearca in scurt timp.', 503, ['Retry-After' => '30', 'Cache-Control' => 'no-store']);
        }
        try {
            return $next($request);
        } finally {
            $barrier->release($lock);
        }
    }
}
