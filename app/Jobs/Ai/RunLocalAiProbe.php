<?php

namespace App\Jobs\Ai;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\LocalAiProbe;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;

class RunLocalAiProbe implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 100;

    public function __construct(public string $runId, public int $userId, public int $workspaceId, #[\SensitiveParameter] public string $prompt, public string $locale)
    {
        $this->onQueue('ai')->afterCommit();
    }

    public function key(): string
    {
        return 'ai-probe:'.$this->userId.':'.$this->workspaceId.':'.$this->runId;
    }

    private function result(array $result): void
    {
        Cache::put($this->key(), Crypt::encryptString(json_encode($result, JSON_THROW_ON_ERROR)), 600);
    }

    public function handle(LocalAiProbe $probe): void
    {
        $user = User::find($this->userId);
        $workspace = Workspace::find($this->workspaceId);
        if (! app()->environment('local') || ! $user || ! $workspace || ! $user->hasVerifiedEmail() || ! Gate::forUser($user)->allows('manage', [Document::class, $workspace])) {
            $this->result(['status' => 'failed', 'reason' => 'ACCESS_REVOKED']);

            return;
        }
        try {
            $this->result(['status' => 'running']);
            $reply = $probe->run($this->prompt, $this->locale);
            $this->result(['status' => 'ready', 'reply' => $reply]);
        } catch (AiProviderException $exception) {
            if ($exception->retryable && $this->attempts() < $this->tries) {
                $this->result(['status' => 'pending']);
                $this->release($exception->retryAfter ?? 15);

                return;
            }
            $this->result(['status' => 'failed', 'reason' => $exception->reason]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->result(['status' => 'failed', 'reason' => 'AI_JOB_FAILED']);
    }
}
