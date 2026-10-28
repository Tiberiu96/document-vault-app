<?php

namespace App\Models;

use App\Notifications\AccountMail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'is_super_admin', 'admin_mfa_secret', 'admin_recovery_hashes', 'admin_mfa_confirmed_at', 'admin_mfa_last_counter', 'admin_mfa_version'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new AccountMail('verify'));
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new AccountMail('reset', $token));
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'memberships')->withPivot('role')->withTimestamps();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'admin_mfa_secret' => 'encrypted',
            'admin_recovery_hashes' => 'encrypted:array',
            'admin_mfa_confirmed_at' => 'datetime',
            'admin_mfa_last_counter' => 'integer',
            'admin_mfa_version' => 'integer',
        ];
    }
}
