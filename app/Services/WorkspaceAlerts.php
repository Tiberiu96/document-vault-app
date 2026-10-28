<?php

namespace App\Services;

use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceAlert;
use Illuminate\Support\Facades\Gate;

class WorkspaceAlerts
{
    public function processingFailed(ProcessingRun $run): void
    {
        if ($run->status !== 'failed') {
            return;
        }
        $document = Document::find($run->document_id);
        $user = User::find($run->requested_by);
        if (! $user || ! $document || $document->status !== 'stored' || ! $user->hasVerifiedEmail() || ! Gate::forUser($user)->allows('view', $document) || ! app(NotificationPreferences::class)->forUser($user)['processing_in_app']) {
            return;
        }
        $user->notify(new WorkspaceAlert($document->workspace_id, 'processing_failed', ['document_id' => $document->id, 'run_id' => $run->id, 'kind' => $run->kind], 'processing:'.$run->id.':'.$user->id));
    }

    public function storage(Workspace $workspace, User $actor): void
    {
        $limit = app(PlanLimitsService::class)->free()['storage_bytes'];
        if ($limit < 1 || $workspace->storage_used_bytes < (int) ceil($limit * .9) || ! Gate::forUser($actor)->allows('view', $workspace) || ! app(NotificationPreferences::class)->forUser($actor)['usage_in_app']) {
            return;
        }
        $actor->notify(new WorkspaceAlert($workspace->id, 'storage_near_limit', [], 'storage:'.$workspace->id.':'.$actor->id.':'.now('UTC')->format('Y-m')));
    }
}
