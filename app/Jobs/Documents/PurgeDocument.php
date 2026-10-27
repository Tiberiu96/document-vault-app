<?php

namespace App\Jobs\Documents;

use App\Models\Document;
use App\Models\Workspace;
use App\Services\Documents\DocumentProcessingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(public string $documentId, public int $workspaceId)
    {
        $this->onQueue('low')->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->documentId;
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        DB::transaction(function () {
            $workspace = Workspace::lockForUpdate()->find($this->workspaceId);
            $document = Document::withTrashed()->where('workspace_id', $this->workspaceId)->lockForUpdate()->find($this->documentId);
            if (! $workspace || ! $document || ! $document->trashed() || ! $document->purge_requested_at) {
                return;
            }
            app(DocumentProcessingService::class)->cancel($document);
            $disk = Storage::disk('local');
            if (! $disk->deleteDirectory(dirname($document->storage_path))) {
                throw new \RuntimeException('Private file cleanup failed.');
            }
            $workspace->forceFill(['storage_used_bytes' => max(0, $workspace->storage_used_bytes - $document->size_bytes - $document->preview_bytes)])->save();
            $document->forceDelete();
        }, 3);
    }
}
