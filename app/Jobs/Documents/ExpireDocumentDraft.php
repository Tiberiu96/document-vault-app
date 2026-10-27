<?php

namespace App\Jobs\Documents;

use App\Models\Document;
use App\Services\Documents\DocumentLibrary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireDocumentDraft implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $documentId, public int $workspaceId)
    {
        $this->onQueue('low')->afterCommit();
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(DocumentLibrary $library): void
    {
        $document = Document::where('workspace_id', $this->workspaceId)->find($this->documentId);
        if ($document) {
            $library->discardDraft($document, expiredOnly: true);
        }
    }
}
