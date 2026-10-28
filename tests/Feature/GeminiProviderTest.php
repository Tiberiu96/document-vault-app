<?php

namespace Tests\Feature;

use App\Contracts\Ai\AiProviderInterface;
use App\Services\Admin\OperationalSummary;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\LocalAiProbe;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true, 'ai.provider' => 'gemini', 'ai.gemini.api_key' => 'fake-gemini-key', 'ai.gemini.model' => 'gemini-3.8-flash']);
        Http::preventStrayRequests();
    }

    private function response(string $text = '{"reply":"Proba fictiva."}', string $finish = 'STOP'): array
    {
        return ['candidates' => [['finishReason' => $finish, 'content' => ['parts' => [['text' => 'private-thought', 'thought' => true], ['text' => $text]]]]]];
    }

    public function test_fixed_endpoint_header_auth_and_structured_output_without_tools_or_query_secrets(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash' => Http::response(['name' => 'models/gemini-3.8-flash', 'supportedGenerationMethods' => ['generateContent']]),
            'generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => Http::response($this->response()),
        ]);
        $this->assertTrue(app(AiProviderInterface::class)->health()['installed']);
        $this->assertSame('Proba fictiva.', app(LocalAiProbe::class)->run('Document fictiv', 'ro'));
        Http::assertSent(fn ($request) => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent'
            && $request->hasHeader('x-goog-api-key', 'fake-gemini-key')
            && $request['contents'][0]['role'] === 'user'
            && str_contains($request['systemInstruction']['parts'][0]['text'], 'Romanian without diacritics')
            && $request['generationConfig']['responseMimeType'] === 'application/json'
            && $request['generationConfig']['responseJsonSchema']['additionalProperties'] === false
            && $request['generationConfig']['thinkingConfig']['thinkingLevel'] === 'low'
            && ! isset($request['tools']));
    }

    public function test_25_flash_uses_thinking_budget_instead_of_thinking_level(): void
    {
        config(['ai.gemini.model' => 'gemini-2.5-flash']);
        Http::fake(['*' => Http::response($this->response())]);
        app(LocalAiProbe::class)->run('Example', 'ro');
        Http::assertSent(fn ($request) => $request['generationConfig']['thinkingConfig'] === ['thinkingBudget' => 0]);
    }

    public function test_configuration_and_prompt_limits_fail_without_network(): void
    {
        foreach ([['ai.enabled' => false], ['ai.enabled' => true, 'ai.gemini.api_key' => ''], ['ai.gemini.api_key' => 'fake', 'ai.gemini.model' => '../private?key=secret']] as $configuration) {
            config($configuration);
            try {
                app(AiProviderInterface::class)->health();
                $this->fail('Expected configuration rejection');
            } catch (AiProviderException $error) {
                $this->assertFalse($error->retryable);
            }
        }
        config(['ai.gemini.model' => 'gemini-3.8-flash']);
        try {
            app(AiProviderInterface::class)->structured([['role' => 'user', 'content' => str_repeat('x', 256001)]], []);
            $this->fail('Expected prompt limit');
        } catch (AiProviderException $error) {
            $this->assertSame('PROMPT_TOO_LARGE', $error->reason);
        }
        Http::assertNothingSent();
    }

    public function test_http_errors_and_retry_info_are_sanitized(): void
    {
        foreach ([400 => ['GEMINI_BAD_REQUEST', false], 403 => ['GEMINI_ACCESS_DENIED', false], 404 => ['GEMINI_MODEL_UNAVAILABLE', false], 413 => ['GEMINI_REQUEST_TOO_LARGE', false], 429 => ['GEMINI_RATE_LIMITED', true], 503 => ['GEMINI_SERVICE_ERROR', true]] as $status => [$code, $retryable]) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response(['error' => ['message' => 'private-response', 'details' => [['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '2.5s']]]], $status)]);
            try {
                app(AiProviderInterface::class)->health();
                $this->fail('Expected provider error');
            } catch (AiProviderException $error) {
                $this->assertSame($code, $error->reason);
                $this->assertSame($retryable, $error->retryable);
                $this->assertSame(3, $error->retryAfter);
                $this->assertSame($retryable, OperationalSummary::retryable($code));
                $this->assertStringNotContainsString('private-response', $error->getMessage());
            }
        }
    }

    public function test_refused_truncated_and_invalid_json_never_become_suggestions(): void
    {
        foreach ([['MAX_TOKENS', '{"reply":"partial"}', 'AI_OUTPUT_TRUNCATED'], ['SAFETY', 'private-response', 'AI_RESPONSE_REFUSED'], ['STOP', 'private-response', 'INVALID_AI_RESPONSE']] as [$finish, $content, $code]) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response($this->response($content, $finish))]);
            try {
                app(LocalAiProbe::class)->run('Example', 'ro');
                $this->fail('Expected rejection');
            } catch (AiProviderException $error) {
                $this->assertSame($code, $error->reason);
                $this->assertStringNotContainsString('private-response', $error->getMessage());
            }
        }
    }
}
