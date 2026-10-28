<?php

namespace App\Jobs\Documents;

use App\Services\Documents\DraftSource;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReleaseDraftSource implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 600;

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
        return [15, 60];
    }

    public function handle(DraftSource $source): void
    {
        $source->release($this->documentId, $this->workspaceId);
    }
}
