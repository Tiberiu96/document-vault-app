<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteReplacedAvatar implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public bool $failOnTimeout = true;

    public function __construct(public int $userId, public string $path) {}

    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function handle(): void
    {
        if (! preg_match('~\Aavatars/'.preg_quote((string) $this->userId, '~').'/[a-f0-9-]{36}\.jpg\z~', $this->path)) {
            return;
        }
        DB::transaction(function (): void {
            $user = User::lockForUpdate()->find($this->userId);
            if ($user?->avatar_path === $this->path) {
                return;
            }
            $disk = Storage::disk('local');
            if ($disk->exists($this->path) && ! $disk->delete($this->path)) {
                throw new \RuntimeException('Avatar cleanup failed.');
            }
        });
    }
}
