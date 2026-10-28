<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminClientRequest;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Admin\AdminAudit;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminClientController extends Controller
{
    public function create()
    {
        return view('admin.client', ['client' => null]);
    }

    public function edit(string $client)
    {
        return view('admin.client', ['client' => User::where('is_super_admin', false)->findOrFail($client)]);
    }

    public function save(AdminClientRequest $request, RegisterUser $registration, ?string $client = null)
    {
        try {
            DB::transaction(function () use ($request, $registration, $client) {
                if ($client) {
                    $record = User::lockForUpdate()->where('is_super_admin', false)->findOrFail($client);
                    $changed = $record->email !== $request->validated('email');
                    $oldEmail = $record->email;
                    $record->fill($request->safe()->only(['name', 'email']));
                    if ($changed) {
                        $record->forceFill(['email_verified_at' => null, 'remember_token' => Str::random(60)]);
                    }
                    $record->save();
                    if ($changed) {
                        $record->tokens()->delete();
                        DB::table('sessions')->where('user_id', $record->id)->delete();
                        DB::table('password_reset_tokens')->whereIn('email', [$oldEmail, $record->email])->delete();
                        $record->sendEmailVerificationNotification();
                    }
                } else {
                    $record = $registration->execute([...$request->safe()->only(['name', 'email']), 'account_type' => 'pf', 'password' => Str::random(64)], 'ro');
                    // Parola initiala este aleatoare, nu se afiseaza si nu se comunica.
                    $token = Password::broker()->createToken($record);
                    $record->sendPasswordResetNotification($token);
                    $record->sendEmailVerificationNotification();
                }
                AdminAudit::record(Auth::guard('admin')->user(), $client ? 'client.updated' : 'client.created', 'user', $record->id);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Adresa este deja utilizata.']);
        }

        return redirect()->route('admin.users')->with('admin_status', 'Client salvat. Emailurile necesare au fost puse pe coada high.');
    }

    public function destroy(Request $request, string $client)
    {
        $request->validate(['confirm' => ['required', 'accepted'], 'current_password' => ['required', 'current_password:admin'], 'email_confirmation' => ['required', 'string']]);
        try {
            DB::transaction(function () use ($request, $client) {
                $record = User::where('is_super_admin', false)->lockForUpdate()->findOrFail($client);
                if (! hash_equals($record->email, trim($request->input('email_confirmation')))) {
                    throw ValidationException::withMessages(['email_confirmation' => 'Reintrodu exact adresa contului pentru confirmare.']);
                }
                $owned = Workspace::where('owner_id', $record->id)->orderBy('id')->lockForUpdate()->get();
                if (Document::withTrashed()->where('uploaded_by', $record->id)->exists() || ProcessingRun::where('requested_by', $record->id)->exists()) {
                    $this->hasData();
                }
                foreach ($owned as $workspace) {
                    if ($workspace->type !== 'personal' || $workspace->storage_used_bytes > 0 || $workspace->members()->where('users.id', '!=', $record->id)->exists() || Document::withTrashed()->where('workspace_id', $workspace->id)->exists()) {
                        $this->hasData();
                    }
                }
                foreach ($owned as $workspace) {
                    $workspace->delete();
                }
                $record->tokens()->delete();
                DB::table('sessions')->where('user_id', $record->id)->delete();
                DB::table('password_reset_tokens')->where('email', $record->email)->delete();
                DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $record->id)->delete();
                AdminAudit::record(Auth::guard('admin')->user(), 'client.deleted', 'user', $record->id);
                $record->delete();
            }, 3);
        } catch (QueryException) {
            $this->hasData();
        }

        return redirect()->route('admin.users')->with('admin_status', 'Contul gol a fost sters definitiv.');
    }

    private function hasData(): never
    {
        throw ValidationException::withMessages(['client' => 'Contul are documente, procesari sau o firma. Transfera responsabilitatile si rezolva retentia datelor inainte de stergere; nu se elimina datele clientului in cascada.']);
    }
}
