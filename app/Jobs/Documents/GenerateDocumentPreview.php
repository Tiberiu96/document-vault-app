<?php

namespace App\Jobs\Documents;

use App\Models\Document;
use App\Models\Workspace;
use App\Services\PlanLimitsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class GenerateDocumentPreview implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public int $sourceRevision = 0;

    public function __construct(public string $documentId, public int $workspaceId, int $sourceRevision = 0)
    {
        $this->sourceRevision = $sourceRevision;
        $this->onQueue('default')->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->documentId.':'.$this->sourceRevision;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $document = Document::where('workspace_id', $this->workspaceId)->find($this->documentId);
        if (! $document || $document->source_released_at || $document->source_revision !== $this->sourceRevision || $document->preview_status === 'ready' || $document->mime_type !== 'application/pdf') {
            return;
        }
        $disk = Storage::disk('local');
        $temporary = sys_get_temp_dir().'/vaulty-preview-'.bin2hex(random_bytes(12));
        if (! mkdir($temporary, 0700)) {
            throw new \RuntimeException('Preview temporary directory unavailable.');
        }
        try {
            $files = [];
            $bytes = 0;
            for ($page = 1; $page <= $document->page_count; $page++) {
                $output = $temporary.'/page-'.$page;
                $process = new Process([config('vault.pdftoppm_binary'), '-f', (string) $page, '-l', (string) $page, '-scale-to', '1400', '-singlefile', '-jpeg', '-jpegopt', 'quality=85', $disk->path($document->storage_path), $output]);
                $process->setTimeout(15);
                $process->disableOutput();
                $process->run();
                $path = $output.'.jpg';
                if (! $process->isSuccessful() || ! is_file($path) || ! @getimagesize($path)) {
                    throw new \RuntimeException('Document preview could not be generated.');
                }
                $files[$page] = $path;
                $bytes += filesize($path);
                if ($bytes > 20 * 1024 * 1024) {
                    throw new \RuntimeException('Document preview exceeds safety limits.');
                }
            }
            DB::transaction(function () use ($files, $bytes, $disk) {
                $workspace = Workspace::lockForUpdate()->find($this->workspaceId);
                $record = Document::where('workspace_id', $this->workspaceId)->lockForUpdate()->find($this->documentId);
                if (! $workspace || ! $record || $record->source_released_at || $record->source_revision !== $this->sourceRevision || $record->preview_status === 'ready') {
                    return;
                }
                app(PlanLimitsService::class)->reserveStorage($workspace, $bytes);
                foreach ($files as $page => $source) {
                    if (! $disk->put(dirname($record->storage_path).'/preview-'.$page.'.jpg', file_get_contents($source), ['visibility' => 'private'])) {
                        throw new \RuntimeException('Document preview storage failed.');
                    }
                }
                $record->forceFill(['preview_status' => 'ready', 'preview_bytes' => $bytes])->save();
            }, 3);
        } catch (ValidationException) {
            // Cota insuficienta este permanenta; originalul ramane disponibil.
            $this->failed(null);
        } catch (\Throwable $exception) {
            $this->cleanPartialPreviews();
            throw $exception;
        } finally {
            foreach (glob($temporary.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($temporary);
        }
    }

    private function cleanPartialPreviews(): void
    {
        DB::transaction(function () {
            Workspace::lockForUpdate()->find($this->workspaceId);
            $record = Document::withTrashed()->where('workspace_id', $this->workspaceId)->whereKey($this->documentId)->lockForUpdate()->first();
            if ($record && $record->source_revision === $this->sourceRevision && ! $record->source_released_at && $record->preview_status !== 'ready') {
                for ($page = 1; $page <= $record->page_count; $page++) {
                    Storage::disk('local')->delete(dirname($record->storage_path).'/preview-'.$page.'.jpg');
                }
            }
        });
    }

    public function failed(?\Throwable $exception): void
    {
        $this->cleanPartialPreviews();
        Document::withTrashed()->where('workspace_id', $this->workspaceId)->whereKey($this->documentId)->where('source_revision', $this->sourceRevision)->whereNull('source_released_at')->where('preview_status', 'pending')->update(['preview_status' => 'failed']);
    }
}
