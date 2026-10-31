<?php

namespace App\Services\Assistant;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class AssistantConcurrency
{
    public function acquire(): ?Lock
    {
        $cache = Cache::store(config('assistant.cache_store'));
        $deadline = microtime(true) + max(0, (int) config('assistant.concurrency_wait_seconds'));
        $limit = max(1, (int) config('assistant.global_concurrency'));

        do {
            for ($slot = 1; $slot <= $limit; $slot++) {
                $lock = $cache->lock('assistant:global:'.$slot, (int) config('assistant.lock_seconds'));
                if ($lock->get()) {
                    return $lock;
                }
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        return null;
    }
}
