<?php

namespace App\Jobs\Documents;

use App\Contracts\Ocr\OcrProviderInterface;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\DocumentAiAnalysis;
use App\Services\Documents\DocumentProcessingService;
use App\Services\Ocr\OcrException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDocument implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout;

    public int $uniqueFor = 1200;

    public function __construct(public string $runId, public int $workspaceId, public string $kind)
    {
        $this->timeout = $kind === 'ocr' ? 240 : 100;
        $this->onQueue($kind === 'ocr' ? 'ocr' : 'ai')->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->runId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(DocumentProcessingService $processing, OcrProviderInterface $ocr, DocumentAiAnalysis $ai): void
    {
        $claimed = $processing->claim($this->runId, $this->workspaceId, $this->attempts());
        if (! $claimed) {
            return;
        }
        [$run, $document] = $claimed;
        $previousProvider = config('ai.provider');
        config(['ai.provider' => $run->provider ?? 'ollama']);
        try {
            $result = $run->kind === 'ocr' ? $ocr->extract($document) : $ai->analyze($document, $run->locale);
            $processing->complete($this->runId, $this->workspaceId, $result);
        } catch (AiProviderException $exception) {
            if ($exception->retryable && $this->attempts() < $this->tries) {
                $this->release($exception->retryAfter ?? 15);
            } else {
                $processing->fail($this->runId, $this->workspaceId, $exception->reason);
            }
        } catch (OcrException $exception) {
            $processing->fail($this->runId, $this->workspaceId, $exception->reason);
        } catch (\Throwable) {
            // Exceptia originala poate contine cai sau text privat; nu o propaga.
            $processing->fail($this->runId, $this->workspaceId, 'PROCESSING_FAILED');
        } finally {
            config(['ai.provider' => $previousProvider]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        app(DocumentProcessingService::class)->fail($this->runId, $this->workspaceId, 'JOB_FAILED');
    }
}
