<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GroqProvider implements AiProviderInterface
{
    private function client(): PendingRequest
    {
        if (! config('ai.enabled')) {
            throw new AiProviderException('AI_DISABLED');
        }
        $key = config('ai.groq.api_key');
        if (! is_string($key) || trim($key) === '' || preg_match('/[\r\n]/', $key)) {
            throw new AiProviderException('MISSING_GROQ_CREDENTIALS');
        }
        if (! in_array(config('ai.groq.model'), ['openai/gpt-oss-20b', 'openai/gpt-oss-120b'], true)) {
            throw new AiProviderException('UNSUPPORTED_GROQ_MODEL');
        }

        return Http::baseUrl('https://api.groq.com/openai/v1')->withToken($key)->acceptJson()->asJson()
            ->connectTimeout(5)->timeout(45)->withOptions(['allow_redirects' => false]);
    }

    private function checked(Response $response): Response
    {
        if (! $response->successful()) {
            $status = $response->status();
            $code = match ($status) {
                401, 403 => 'INVALID_GROQ_CREDENTIALS',
                429 => 'GROQ_RATE_LIMITED',
                400 => 'GROQ_BAD_REQUEST',
                413 => 'GROQ_REQUEST_TOO_LARGE',
                422 => 'GROQ_UNPROCESSABLE_REQUEST',
                408, 504 => 'GROQ_REQUEST_TIMEOUT',
                500, 502, 503 => 'GROQ_SERVICE_ERROR',
                default => 'GROQ_HTTP_ERROR',
            };
            $retry = $response->header('Retry-After');
            throw new AiProviderException($code, in_array($status, [408, 429, 500, 502, 503, 504], true), is_numeric($retry) ? min(300, max(1, (int) ceil((float) $retry))) : null);
        }

        return $response;
    }

    public function health(): array
    {
        try {
            $models = $this->checked($this->client()->get('/models'))->json('data');
            if (! is_array($models)) {
                throw new AiProviderException('INVALID_AI_RESPONSE');
            }

            return ['provider' => 'groq', 'model' => config('ai.groq.model'), 'installed' => collect($models)->contains(fn ($model) => ($model['id'] ?? null) === config('ai.groq.model'))];
        } catch (ConnectionException) {
            throw new AiProviderException('GROQ_UNAVAILABLE', true);
        }
    }

    private function transportSchema(array $schema): array
    {
        // Constrangerile nesuportate de decodarea stricta raman validate de serviciul de domeniu.
        foreach (['maxLength', 'minLength', 'pattern', 'maxItems', 'minItems', 'uniqueItems'] as $key) {
            unset($schema[$key]);
        }
        foreach ($schema['properties'] ?? [] as $key => $property) {
            $schema['properties'][$key] = $this->transportSchema($property);
        }
        if (isset($schema['items'])) {
            $schema['items'] = $this->transportSchema($schema['items']);
        }

        return $schema;
    }

    public function structured(array $messages, array $schema): array
    {
        if (strlen(json_encode([$messages, $schema], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > config('ai.groq.max_prompt_bytes', 64000)) {
            throw new AiProviderException('PROMPT_TOO_LARGE');
        }
        try {
            $response = $this->checked($this->client()->post('/chat/completions', [
                'model' => config('ai.groq.model'), 'messages' => $messages,
                'temperature' => 0, 'max_completion_tokens' => 4096, 'reasoning_effort' => 'low', 'stream' => false,
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'vault_document', 'strict' => true, 'schema' => $this->transportSchema($schema)]],
            ]));
        } catch (ConnectionException) {
            throw new AiProviderException('GROQ_UNAVAILABLE', true);
        }
        $content = $response->json('choices.0.message.content');
        if ($response->json('choices.0.finish_reason') === 'length') {
            // Nu accepta JSON partial si nu repeta automat o analiza facturabila.
            throw new AiProviderException('AI_OUTPUT_TRUNCATED');
        }
        if ($response->json('choices.0.message.refusal')) {
            throw new AiProviderException('AI_RESPONSE_REFUSED');
        }
        if ($response->json('choices.0.finish_reason') !== 'stop' || ! is_string($content) || strlen($content) > 32000 || $response->json('choices.0.message.refusal')) {
            throw new AiProviderException('INVALID_AI_RESPONSE');
        }
        try {
            $result = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiProviderException('INVALID_AI_RESPONSE');
        }
        if (! is_array($result) || array_is_list($result)) {
            throw new AiProviderException('INVALID_AI_RESPONSE');
        }

        return $result;
    }
}
