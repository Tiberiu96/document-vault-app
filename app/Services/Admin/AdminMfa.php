<?php

namespace App\Services\Admin;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class AdminMfa
{
    public function begin(Request $request, User $user): array
    {
        abort_if($user->admin_mfa_confirmed_at, 409);
        $pending = $request->session()->get('admin.enrollment');
        if (! is_array($pending) || ($pending['user_id'] ?? null) !== $user->id || ($pending['at'] ?? 0) < now()->timestamp - config('admin.password_step_seconds')) {
            $pending = ['user_id' => $user->id, 'at' => now()->timestamp, 'secret' => Crypt::encryptString((new Google2FA)->generateSecretKey(32))];
            $request->session()->put('admin.enrollment', $pending);
        }
        $secret = Crypt::decryptString($pending['secret']);
        $uri = (new Google2FA)->getQRCodeUrl(config('app.name').' Admin', $user->email, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd));

        return ['secret' => $secret, 'qr' => $writer->writeString($uri)];
    }

    public function enroll(Request $request, User $user, #[\SensitiveParameter] string $code): array
    {
        $pending = $request->session()->get('admin.enrollment');
        if (! is_array($pending) || ($pending['user_id'] ?? null) !== $user->id || ($pending['at'] ?? 0) < now()->timestamp - config('admin.password_step_seconds')) {
            throw ValidationException::withMessages(['code' => 'Configurarea a expirat. Reincarca pagina.']);
        }

        return DB::transaction(function () use ($pending, $user, $code) {
            $record = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($record->is_super_admin && ! $record->admin_mfa_confirmed_at, 409);
            $secret = Crypt::decryptString($pending['secret']);
            $counter = $this->counter($secret, $code, null);
            if ($counter === false) {
                $this->invalid();
            }
            $codes = $this->codes();
            $record->forceFill(['admin_mfa_secret' => $secret, 'admin_mfa_confirmed_at' => now(), 'admin_mfa_last_counter' => $counter, 'admin_mfa_version' => $record->admin_mfa_version + 1, 'admin_recovery_hashes' => array_map(fn ($value) => hash('sha256', $this->normalize($value)), $codes)])->save();
            AdminAudit::record($record, 'mfa.enrolled', 'user', $record->id);

            return $codes;
        }, 3);
    }

    public function verify(User $user, #[\SensitiveParameter] string $code): bool
    {
        return DB::transaction(function () use ($user, $code) {
            $record = User::lockForUpdate()->findOrFail($user->id);
            if (! $record->is_super_admin || ! $record->admin_mfa_confirmed_at || ! $record->admin_mfa_secret) {
                return false;
            }
            if (preg_match('/\A[0-9]{6}\z/', $code)) {
                $counter = $this->counter($record->admin_mfa_secret, $code, $record->admin_mfa_last_counter);
                if ($counter === false) {
                    return false;
                }
                $record->forceFill(['admin_mfa_last_counter' => $counter])->save();
                AdminAudit::record($record, 'mfa.verified', 'user', $record->id);

                return true;
            }
            $hash = hash('sha256', $this->normalize($code));
            $codes = $record->admin_recovery_hashes ?? [];
            foreach ($codes as $index => $stored) {
                if (hash_equals($stored, $hash)) {
                    unset($codes[$index]);
                    $record->forceFill(['admin_recovery_hashes' => array_values($codes), 'admin_mfa_version' => $record->admin_mfa_version + 1])->save();
                    AdminAudit::record($record, 'mfa.recovery_used', 'user', $record->id);

                    return true;
                }
            }

            return false;
        }, 3);
    }

    public function regenerateRecovery(User $user, #[\SensitiveParameter] string $code): array
    {
        return DB::transaction(function () use ($user, $code) {
            $record = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($record->is_super_admin && $record->admin_mfa_confirmed_at, 403);
            $counter = $this->counter($record->admin_mfa_secret, $code, $record->admin_mfa_last_counter);
            if ($counter === false) {
                $this->invalid();
            }
            $codes = $this->codes();
            $record->forceFill(['admin_recovery_hashes' => array_map(fn ($value) => hash('sha256', $this->normalize($value)), $codes), 'admin_mfa_last_counter' => $counter, 'admin_mfa_version' => $record->admin_mfa_version + 1])->save();
            AdminAudit::record($record, 'mfa.recovery_regenerated', 'user', $record->id);

            return $codes;
        }, 3);
    }

    private function counter(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code, ?int $previous): int|false
    {
        if (! preg_match('/\A[0-9]{6}\z/', $code)) {
            return false;
        }
        try {
            return (new Google2FA)->verifyKeyNewer($secret, $code, $previous ?? 0, 1, intdiv(now()->timestamp, 30));
        } catch (\Throwable) {
            return false;
        }
    }

    private function codes(): array
    {
        return array_map(fn () => implode('-', str_split(strtoupper(bin2hex(random_bytes(16))), 4)), range(1, 8));
    }

    private function normalize(#[\SensitiveParameter] string $value): string
    {
        return strtoupper(preg_replace('/[-\s]/', '', $value));
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['code' => 'Cod invalid sau deja folosit. Foloseste un cod nou.']);
    }
}
