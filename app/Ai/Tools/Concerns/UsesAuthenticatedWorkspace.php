<?php

namespace App\Ai\Tools\Concerns;

use App\Models\User;
use App\Models\Workspace;

trait UsesAuthenticatedWorkspace
{
    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function workspace(): Workspace
    {
        $user = $this->user();
        $workspace = request()->attributes->get('workspace');
        abort_unless($workspace instanceof Workspace && $user->workspaces()->whereKey($workspace->id)->exists(), 403);

        return $workspace;
    }
}
