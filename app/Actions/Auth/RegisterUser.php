<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegisterUser
{
    public function execute(array $data, string $locale): User
    {
        $passwordHash = Hash::make($data['password']);

        return DB::transaction(function () use ($data, $locale, $passwordHash): User {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'password' => $passwordHash,
            ]);
            $this->workspace($user, 'personal', trans('auth.personal', [], $locale));

            if ($data['account_type'] === 'pj') {
                $business = $this->workspace($user, 'business', $data['company_name']);
                $business->company()->create([
                    'legal_name' => $data['company_name'], 'cui' => $data['cui'],
                    'registration_number' => $data['registration_number'],
                    'registered_address' => [
                        'county' => $data['county'], 'city' => $data['city'], 'street' => $data['street'],
                        'postal_code' => $data['postal_code'] ?? null,
                    ],
                    'country_code' => 'RO',
                ]);
            }

            return $user;
        }, 3);
    }

    private function workspace(User $user, string $type, string $name): Workspace
    {
        $workspace = new Workspace;
        $workspace->forceFill(['owner_id' => $user->id, 'type' => $type, 'name' => $name])->save();
        $workspace->members()->attach($user->id, ['role' => 'owner']);

        return $workspace;
    }
}
