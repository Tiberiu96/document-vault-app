<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class CreateAdministrator extends Command
{
    protected $signature = 'vault:admin:create';

    protected $description = 'Creeaza interactiv un administrator global, fara credentiale implicite.';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Comanda necesita un terminal interactiv.');

            return self::FAILURE;
        }

        try {
            DB::connection()->getPdo();
            if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'is_super_admin')) {
                $this->error('Schema nu este pregatita. Ruleaza php artisan migrate pe baza Vault configurata.');

                return self::FAILURE;
            }
        } catch (Throwable) {
            $this->error('Baza de date nu este disponibila. Verifica DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME si DB_PASSWORD in .env.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Nume administrator'));
        $email = mb_strtolower(trim((string) $this->ask('Email administrator')));
        $identity = Validator::make(compact('name', 'email'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        if ($identity->fails()) {
            $this->error('Numele sau emailul este invalid, ori emailul este deja folosit.');

            return self::FAILURE;
        }

        $this->line('Parola: minimum 16 caractere, maximum 72 bytes, litere mari/mici, cifre si simboluri.');
        $password = $this->secret('Parola administrator', false);
        $confirmation = $this->secret('Confirma parola', false);
        $validation = Validator::make([
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(16)->mixedCase()->numbers()->symbols()],
        ]);

        if ($validation->fails() || strlen((string) $password) > 72) {
            $this->error('Parola nu respecta cerintele sau confirmarea nu coincide.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($name, $email, $password): void {
                $user = new User;
                $user->name = $name;
                $user->email = $email;
                $user->password = Hash::make($password);
                $user->is_super_admin = true;
                $user->save();
            });
        } catch (UniqueConstraintViolationException) {
            $this->error('Emailul este deja folosit. Nu a fost modificat niciun cont existent.');

            return self::FAILURE;
        }

        $this->info('Administrator creat. Accesul web admin ramane blocat pana la implementarea autentificarii si MFA.');

        return self::SUCCESS;
    }
}
