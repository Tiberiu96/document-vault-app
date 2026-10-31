<?php

namespace App\Http\Controllers;

use App\Ai\Agents\VaultyAssistant;
use App\Http\Requests\AssistantMessageRequest;
use App\Services\Assistant\AssistantConcurrency;
use App\Services\PlanLimitsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AssistantController extends Controller
{
    public function __invoke(AssistantMessageRequest $request, AssistantConcurrency $concurrency, PlanLimitsService $plans): StreamedResponse|JsonResponse
    {
        abort_unless(config('assistant.enabled') && config('ai.enabled'), 404);
        $user = $request->user();
        $workspace = $request->attributes->get('workspace');
        $provider = (string) config('assistant.provider');
        abort_unless(in_array($provider, ['ollama', 'gemini'], true), 500);
        $store = Cache::store(config('assistant.cache_store'));
        $userLock = $store->lock('assistant:user:'.$user->id, (int) config('assistant.lock_seconds'));
        if (! $userLock->get()) {
            return response()->json(['message' => __('assistant.active_response')], 429);
        }
        $slot = $concurrency->acquire();
        if (! $slot) {
            $userLock->release();

            return response()->json(['message' => __('assistant.busy')], 503);
        }

        try {
            $plan = $plans->resolve($workspace)['code'];
            $limit = (int) config('assistant.daily_messages.'.$plan, config('assistant.daily_messages.free'));
            $rateKey = 'assistant:daily:'.$user->id.':'.now('UTC')->toDateString();
            if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
                $slot->release();
                $userLock->release();

                return response()->json(['message' => __('assistant.daily_limit')], 429);
            }
            RateLimiter::hit($rateKey, (int) max(1, now('UTC')->diffInSeconds(now('UTC')->endOfDay())));

            $messages = collect($request->validated('messages'));
            $prompt = trim((string) $messages->last()['content']);
            $history = $messages->slice(0, -1)->map(fn (array $message) => new Message($message['role'], $message['content']))->all();
            $model = config('assistant.model');
            $stream = (new VaultyAssistant)->withMessages($history)->stream(
                $prompt,
                provider: $provider,
                model: is_string($model) && $model !== '' ? $model : null,
                timeout: (int) config('assistant.timeout_seconds'),
            );
        } catch (Throwable $exception) {
            $slot->release();
            $userLock->release();
            throw $exception;
        }

        return response()->stream(function () use ($stream, $user, $workspace, $userLock, $slot) {
            $inputTokens = 0;
            $outputTokens = 0;
            try {
                foreach ($stream as $event) {
                    if (connection_aborted()) {
                        break;
                    }
                    $payload = match (true) {
                        $event instanceof ToolCall => ['type' => 'tool_call', 'name' => $event->toolCall->name],
                        $event instanceof ToolResult => ['type' => 'tool_result', 'name' => $event->toolResult->name],
                        $event instanceof TextDelta => ['type' => 'text', 'text' => $event->delta],
                        default => null,
                    };
                    if ($payload !== null) {
                        $this->send($payload);
                    }
                    if ($event instanceof StreamEnd) {
                        $inputTokens += $event->usage->inputTokens;
                        $outputTokens += $event->usage->outputTokens;
                    }
                }
                DB::table('assistant_usage')->insert([
                    'user_id' => $user->id,
                    'workspace_id' => $workspace->id,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'created_at' => now(),
                ]);
                $this->send(['type' => 'done']);
            } catch (Throwable $exception) {
                Log::warning('Assistant stream failed.', ['exception' => $exception::class]);
                $this->send(['type' => 'error', 'message' => __('assistant.failed')]);
                $this->send(['type' => 'done']);
            } finally {
                $slot->release();
                $userLock->release();
            }
        }, 200, [
            'Cache-Control' => 'no-cache, no-store, no-transform, private',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function send(array $payload): void
    {
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
