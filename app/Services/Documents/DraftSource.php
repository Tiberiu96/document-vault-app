<?php

namespace App\Services\Documents;

use App\Jobs\Documents\ReleaseDraftSource;
use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DraftSource
{
    public function release(string $documentId, int $workspaceId): void
    {
        DB::transaction(function () use ($documentId, $workspaceId) {
            $workspace = Workspace::lockForUpdate()->find($workspaceId);
            $document = Document::where('workspace_id', $workspaceId)->lockForUpdate()->find($documentId);
            if (! $workspace || ! $document || ! $document->isDraft()) {
                return;
            }
            if ($document->source_released_at) {
                if (! Storage::disk('local')->deleteDirectory(dirname($document->storage_path))) {
                    throw new \RuntimeException('Draft orphan cleanup failed.');
                }

                return;
            }
            if (in_array($document->ocr_status, ['queued', 'running', 'idle'], true)
                || in_array($document->ai_status, ['queued', 'running'], true)
                || ($document->ai_requested && $document->ocr_status === 'ready' && $document->ai_status === 'idle')) {
                return;
            }
            if (! Storage::disk('local')->deleteDirectory(dirname($document->storage_path))) {
                throw new \RuntimeException('Draft source cleanup failed.');
            }
            $workspace->forceFill(['storage_used_bytes' => max(0, $workspace->storage_used_bytes - $document->size_bytes - $document->preview_bytes)])->save();
            $document->forceFill(['source_released_at' => now(), 'source_revision' => $document->source_revision + 1, 'preview_bytes' => 0, 'preview_status' => 'awaiting_source'])->save();
        }, 3);
    }

    public function releaseOrQueue(string $documentId, int $workspaceId): void
    {
        try {
            $this->release($documentId, $workspaceId);
        } catch (\Throwable) {
            ReleaseDraftSource::dispatch($documentId, $workspaceId);
        }
    }
}
