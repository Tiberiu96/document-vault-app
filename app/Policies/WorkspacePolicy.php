<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $workspace->type === 'business' && $this->view($user, $workspace)
            && in_array($workspace->members()->whereKey($user->id)->value('role'), ['owner', 'admin'], true);
    }

    public function view(User $user, Workspace $workspace): bool
    {
        if ($workspace->type === 'personal' && $workspace->owner_id !== $user->id) {
            return false;
        }

        return $workspace->members()->whereKey($user->id)->exists();
    }
}
