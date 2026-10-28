<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GeminiProvider implements AiProviderInterface
{
    private function model(): string
    {
        $model = config('ai.gemini.model');
        if (! is_string($model) || ! preg_match('/\Agemini-[a-z0-9.-]+\z/', $model)) {
            throw new AiProviderException('UNSUPPORTED_GEMINI_MODEL');
        }

        return $model;
    }

    private function client(): PendingRequest
    {
        if (! config('ai.enabled')) {
            throw new AiProviderException('AI_DISABLED');
        }
        $key = config('ai.gemini.api_key');
        if (! is_string($key) || trim($key) === '' || preg_match('/[\r\n]/', $key)) {
            throw new AiProviderException('MISSING_GEMINI_CREDENTIALS');
        }

        return Http::baseUrl('https://generativelanguage.googleapis.com/v1beta')
            ->withHeaders(['x-goog-api-key' => $key])->acceptJson()->asJson()
            ->connectTimeout(5)->timeout(60)->withOptions(['allow_redirects' => false]);
    }

    private function checked(Response $response): Response
    {
        if (! $response->successful()) {
            $status = $response->status();
            $code = match ($status) {
                400 => 'GEMINI_BAD_REQUEST',
                401, 403 => 'GEMINI_ACCESS_DENIED',
                404 => 'GEMINI_MODEL_UNAVAILABLE',
                413 => 'GEMINI_REQUEST_TOO_LARGE',
                429 => 'GEMINI_RATE_LIMITED',
                408, 504 => 'GEMINI_REQUEST_TIMEOUT',
                500, 502, 503 => 'GEMINI_SERVICE_ERROR',
                default => 'GEMINI_HTTP_ERROR',
            };
            $retry = $response->header('Retry-After');
            $details = $response->json('error.details');
            foreach (is_array($details) ? $details : [] as $detail) {
                if (! is_array($detail)) {
                    continue;
                }
                if (($detail['@type'] ?? '') === 'type.googleapis.com/google.rpc.RetryInfo'
                    && is_string($detail['retryDelay'] ?? null)
                    && preg_match('/\A([0-9]+(?:\.[0-9]+)?)s\z/', $detail['retryDelay'] ?? '', $matches)) {
                    $retry = $matches[1];
                }
            }
            throw new AiProviderException($code, in_array($status, [408, 429, 500, 502, 503, 504], true), is_numeric($retry) ? min(300, max(1, (int) ceil((float) $retry))) : null);
        }

        return $response;
    }

    public function health(): array
    {
        $model = $this->model();
        try {
            $data = $this->checked($this->client()->get('/models/'.$model))->json();
            if (! is_array($data) || ($data['name'] ?? '') !== 'models/'.$model) {
                throw new AiProviderException('INVALID_AI_RESPONSE');
            }

            return ['provider' => 'gemini', 'model' => $model, 'installed' => in_array('generateContent', $data['supportedGenerationMethods'] ?? [], true)];
        } catch (ConnectionException) {
            throw new AiProviderException('GEMINI_UNAVAILABLE', true);
        }
    }

    public function structured(array $messages, array $schema): array
    {
        $model = $this->model();
        if (strlen(json_encode([$messages, $schema], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > config('ai.gemini.max_prompt_bytes', 256000)) {
            throw new AiProviderException('PROMPT_TOO_LARGE');
        }
        $system = [];
        $contents = [];
        foreach ($messages as $message) {
            if (! is_string($message['content'] ?? null) || ! in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true)) {
                throw new AiProviderException('INVALID_AI_REQUEST');
            }
            if ($message['role'] === 'system') {
                $system[] = ['text' => $message['content']];
            } else {
                $contents[] = ['role' => $message['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $message['content']]]];
            }
        }
        $generation = ['temperature' => 0, 'maxOutputTokens' => 4096,
            'responseMimeType' => 'application/json', 'responseJsonSchema' => $schema];
        if (str_starts_with($model, 'gemini-2.5-')) {
            $generation['thinkingConfig'] = ['thinkingBudget' => 0];
        } else {
            $generation['thinkingConfig'] = ['thinkingLevel' => 'low'];
        }
        $payload = ['contents' => $contents, 'generationConfig' => $generation];
        if ($system) {
            $payload['systemInstruction'] = ['parts' => $system];
        }
        try {
            $response = $this->checked($this->client()->post('/models/'.$model.':generateContent', $payload));
        } catch (ConnectionException) {
            throw new AiProviderException('GEMINI_UNAVAILABLE', true);
        }
        if ($response->json('promptFeedback.blockReason')) {
            throw new AiProviderException('AI_RESPONSE_REFUSED');
        }
        $candidate = $response->json('candidates.0');
        $finish = $candidate['finishReason'] ?? null;
        if ($finish === 'MAX_TOKENS') {
            throw new AiProviderException('AI_OUTPUT_TRUNCATED');
        }
        if (in_array($finish, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'], true)) {
            throw new AiProviderException('AI_RESPONSE_REFUSED');
        }
        if ($finish !== 'STOP' || ! is_array($candidate['content']['parts'] ?? null)) {
            throw new AiProviderException('INVALID_AI_RESPONSE');
        }
        $content = '';
        foreach ($candidate['content']['parts'] as $part) {
            if (! empty($part['thought'])) {
                continue;
            }
            if (! is_string($part['text'] ?? null)) {
                throw new AiProviderException('INVALID_AI_RESPONSE');
            }
            $content .= $part['text'];
        }
        if (strlen($content) > 32000) {
            throw new AiProviderException('INVALID_AI_RESPONSE');
        }
        try {
            $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiProviderException('INVALID_AI_RESPONSE');
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new AiProviderException('INVALID_AI_RESPONSE');
        }

        return $data;
    }
}
