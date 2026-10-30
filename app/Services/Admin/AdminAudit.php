<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminAudit
{
    public static function label(string $action): string
    {
        return match ($action) {
            'auth.password_verified' => 'Parola verificata', 'auth.logout' => 'Deconectare',
            'mfa.enrolled' => 'MFA activat', 'mfa.verified' => 'MFA verificat',
            'mfa.recovery_used' => 'Cod de recuperare folosit', 'mfa.recovery_regenerated' => 'Coduri de recuperare regenerate',
            'mfa.reset_from_console' => 'MFA resetat din consola',
            'catalog.created' => 'Element de catalog creat', 'catalog.updated' => 'Element de catalog actualizat', 'catalog.deleted' => 'Element de catalog sters',
            'language.created' => 'Limba creata', 'language.updated' => 'Limba actualizata', 'language.deleted' => 'Limba stearsa', 'language.default_changed' => 'Limba implicita schimbata',
            'plan.upgrade_approved' => 'Cerere upgrade aprobata', 'plan.upgrade_rejected' => 'Cerere upgrade refuzata', 'plan.member_seats_updated' => 'Locuri suplimentare actualizate', 'plan.limits_updated' => 'Limite actualizate', 'processing.retry_requested' => 'Reprocesare solicitata',
            'plan.assigned_free' => 'Plan Free atribuit manual', 'plan.assigned_pro' => 'Plan Pro atribuit manual',
            'client.created' => 'Client creat', 'client.updated' => 'Client actualizat', 'client.deleted' => 'Client sters',
            'impersonation.started' => 'Consultare cont client inceputa', 'impersonation.stopped' => 'Consultare cont client oprita', 'impersonation.expired' => 'Consultare cont client expirata',
            default => 'Operatie administrativa',
        };
    }

    public static function record(User $actor, string $action, ?string $type = null, string|int|null $id = null): void
    {
        DB::table('admin_audit_events')->insert(['actor_id' => $actor->id, 'action' => $action, 'target_type' => $type, 'target_id' => $id === null ? null : (string) $id, 'created_at' => now()]);
    }
}
