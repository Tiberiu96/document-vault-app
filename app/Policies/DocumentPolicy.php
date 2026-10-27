<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return (! in_array($document->status, ['draft', 'discarded'], true) || $document->uploaded_by === $user->id) && (new WorkspacePolicy)->view($user, $document->workspace);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return (new WorkspacePolicy)->view($user, $workspace) && in_array($workspace->members()->whereKey($user->id)->value('role'), ['owner', 'admin', 'member'], true);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->view($user, $document) && $this->create($user, $document->workspace) && ($document->uploaded_by === $user->id || $this->manage($user, $document->workspace));
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }

    public function manage(User $user, Workspace $workspace): bool
    {
        return (new WorkspacePolicy)->view($user, $workspace) && in_array($workspace->members()->whereKey($user->id)->value('role'), ['owner', 'admin'], true);
    }
}
