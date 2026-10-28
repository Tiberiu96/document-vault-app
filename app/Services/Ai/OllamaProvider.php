<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OllamaProvider implements AiProviderInterface
{
    private function client(): PendingRequest
    {
        if (config('ai.provider') !== 'ollama') {
            throw new AiProviderException('UNSUPPORTED_AI_PROVIDER');
        }
        if (! config('ai.enabled')) {
            throw new AiProviderException('AI_DISABLED');
        }
        $url = config('ai.ollama.base_url');
        $parts = is_string($url) ? parse_url($url) : false;
        if (! $parts || ($parts['scheme'] ?? '') !== 'http' || ! in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new AiProviderException('UNSAFE_AI_CONFIGURATION');
        }
        $model = config('ai.ollama.model');
        if (! is_string($model) || ! preg_match('/\A[a-zA-Z0-9_.:\/-]+\z/', $model) || str_contains(strtolower($model), 'cloud')) {
            throw new AiProviderException('UNSAFE_AI_CONFIGURATION');
        }

        return Http::baseUrl(rtrim($url, '/'))->acceptJson()->asJson()->connectTimeout(3)
            ->timeout(max(5, min(90, (int) config('ai.ollama.timeout'))))->withOptions(['allow_redirects' => false]);
    }

    private function checked(Response $response): Response
    {
        if (! $response->successful()) {
            $status = $response->status();
            $retry = $response->header('Retry-After');
            throw new AiProviderException($status === 404 ? 'MODEL_NOT_INSTALLED' : 'OLLAMA_HTTP_ERROR', in_array($status, [408, 429, 500, 502, 503, 504], true), ctype_digit($retry ?? '') ? min(300, max(1, (int) $retry)) : null);
        }

        return $response;
    }

    public function health(): array
    {
        try {
            $version = $this->checked($this->client()->timeout(5)->get('/api/version'))->json('version');
            $models = $this->checked($this->client()->timeout(5)->get('/api/tags'))->json('models');
            if (! is_string($version) || ! is_array($models)) {
                throw new AiProviderException('INVALID_AI_RESPONSE');
            }

            return ['provider' => 'ollama', 'version' => $version, 'model' => config('ai.ollama.model'), 'installed' => collect($models)->contains(fn ($model) => ($model['name'] ?? $model['model'] ?? '') === config('ai.ollama.model'))];
        } catch (ConnectionException) {
            throw new AiProviderException('OLLAMA_UNAVAILABLE', true);
        }
    }

    public function structured(array $messages, array $schema): array
    {
        // Limita conservatoare: inputul, raspunsul si template-ul incap in context.
        $budget = app(OllamaContext::class)->inputBudget();
        if (strlen(json_encode([$messages, $schema], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > $budget) {
            throw new AiProviderException('PROMPT_TOO_LARGE');
        }
        try {
            $response = $this->checked($this->client()->post('/api/chat', [
                'model' => config('ai.ollama.model'), 'messages' => $messages, 'format' => $schema,
                'stream' => false, 'think' => false, 'keep_alive' => '10m',
                'options' => ['temperature' => 0, 'num_ctx' => (int) config('ai.ollama.context_length'), 'num_predict' => (int) config('ai.ollama.max_output_tokens')],
            ]));
        } catch (ConnectionException) {
            throw new AiProviderException('OLLAMA_UNAVAILABLE', true);
        }
        $content = $response->json('message.content');
        if ($response->json('done_reason') === 'length') {
            throw new AiProviderException('AI_OUTPUT_TRUNCATED');
        }
        if (! is_string($content) || strlen($content) > 32000 || $response->json('done') !== true || $response->json('done_reason') === 'length') {
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
