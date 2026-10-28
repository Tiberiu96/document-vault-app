<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\AdminAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ResetAdministratorMfa extends Command
{
    protected $signature = 'vault:admin:mfa-reset';

    protected $description = 'Recuperare MFA din terminal, cu parola curenta si confirmare explicita.';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Comanda necesita un terminal interactiv.');

            return self::FAILURE;
        }
        $email = mb_strtolower(trim((string) $this->ask('Email administrator')));
        $password = (string) $this->secret('Parola curenta', false);
        $user = User::where('email', $email)->where('is_super_admin', true)->first();
        if (! $user || ! Hash::check($password, $user->password)) {
            $this->error('Date incorecte. Nu s-a modificat nimic.');

            return self::FAILURE;
        }
        if (! $this->confirm('Resetezi MFA si invalidezi toate sesiunile administrative?', false)) {
            return self::FAILURE;
        }
        DB::transaction(function () use ($user, $password) {
            $record = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($record->is_super_admin && Hash::check($password, $record->password), 403);
            $record->forceFill(['admin_mfa_secret' => null, 'admin_recovery_hashes' => null, 'admin_mfa_confirmed_at' => null, 'admin_mfa_last_counter' => null, 'admin_mfa_version' => $record->admin_mfa_version + 1])->save();
            AdminAudit::record($record, 'mfa.reset_from_console', 'user', $record->id);
        }, 3);
        $this->info('MFA resetat. Configureaza din nou MFA la /admin/autentificare.');

        return self::SUCCESS;
    }
}
