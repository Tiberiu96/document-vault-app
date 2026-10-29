<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\AdminMfa;
use App\Services\Backup\BackupInspection;
use App\Services\Backup\BackupRestoreControl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminBackupController extends Controller
{
    public function status()
    {
        Gate::authorize('platform.admin');
        $state = app(BackupRestoreControl::class)->read('status');

        return response()->json(array_intersect_key($state, array_flip(['phase', 'error_code', 'finished_at'])));
    }

    public function inspect(Request $request)
    {
        Gate::authorize('platform.admin');
        abort_unless(app()->environment('production') && config('backup.enabled'), 403);
        try {
            app(BackupInspection::class)->refresh();
        } catch (\Throwable) {
            return back()->withErrors(['backup' => 'Nu s-au putut actualiza detaliile R2. Datele anterioare raman disponibile.']);
        }

        return back()->with('admin_status', 'Lista copiilor si ocuparea bucketului au fost actualizate.');
    }

    public function restore(Request $request)
    {
        Gate::authorize('platform.admin');
        abort_unless(app()->environment('production') && config('backup.enabled') && config('backup.restore_enabled'), 403);
        $data = $request->validate([
            'snapshot' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'scope' => ['required', 'in:all,mysql,documents'],
            'password' => ['required', 'string'], 'code' => ['required', 'regex:/\A[0-9]{6}\z/'],
            'confirmation' => ['required', 'in:RESTAUREAZA'], 'confirm' => ['required', 'accepted'],
        ]);
        $user = Auth::guard('admin')->user();
        if (! Hash::check($data['password'], $user->password) || ! app(AdminMfa::class)->verify($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Parola sau codul MFA este invalid. Foloseste un cod nou.']);
        }
        try {
            $restoreId = app(BackupRestoreControl::class)->request($data['snapshot'], $data['scope'], $user->id);
        } catch (\Throwable) {
            return back()->withErrors(['backup' => 'Cererea nu a fost acceptata. Actualizeaza lista copiilor si verifica starea restaurarii.']);
        }
        AdminAudit::record($user, 'backup.restore_requested_'.$data['scope'], 'backup', $restoreId);

        return back()->with('admin_status', 'Restaurare solicitata. Aplicatia va intra in mentenanta. Dupa succes vei fi deconectat; autentifica-te din nou. La esec, mentenanta ramane activa.');
    }
}
