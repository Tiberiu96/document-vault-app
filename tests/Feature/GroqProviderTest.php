<?php

namespace Tests\Feature;

use App\Contracts\Ai\AiProviderInterface;
use App\Services\Admin\OperationalSummary;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\LocalAiProbe;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroqProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true, 'ai.provider' => 'groq', 'ai.groq.api_key' => 'fake-test-key', 'ai.groq.model' => 'openai/gpt-oss-20b']);
        Http::preventStrayRequests();
    }

    public function test_groq_uses_fixed_https_endpoint_and_strict_schema_without_tools(): void
    {
        Http::fake([
            'api.groq.com/openai/v1/models' => Http::response(['data' => [['id' => 'openai/gpt-oss-20b']]]),
            'api.groq.com/openai/v1/chat/completions' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"reply":"Proba fictiva."}']]]]),
        ]);
        $this->assertTrue(app(AiProviderInterface::class)->health()['installed']);
        $this->assertSame('Proba fictiva.', app(LocalAiProbe::class)->run('Explica PDF.', 'ro'));
        Http::assertSent(fn ($r) => $r->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer fake-test-key') && $r['response_format']['json_schema']['strict'] === true
            && $r['reasoning_effort'] === 'low' && $r['max_completion_tokens'] === 4096 && ! isset($r['tools']) && $r['stream'] === false);
    }

    public function test_disabled_or_missing_credentials_fail_without_network(): void
    {
        config(['ai.groq.api_key' => '']);
        try {
            app(AiProviderInterface::class)->health();
            $this->fail('Expected configuration rejection');
        } catch (AiProviderException $e) {
            $this->assertSame('MISSING_GROQ_CREDENTIALS', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_oversized_prompt_is_rejected_without_external_request_or_truncation(): void
    {
        try {
            app(AiProviderInterface::class)->structured([['role' => 'user', 'content' => str_repeat('x', 64000)]], ['type' => 'object']);
            $this->fail('Expected prompt limit');
        } catch (AiProviderException $error) {
            $this->assertSame('PROMPT_TOO_LARGE', $error->reason);
            $this->assertFalse($error->retryable);
        }
        Http::assertNothingSent();
    }

    public function test_errors_are_sanitized_with_bounded_retry_after(): void
    {
        foreach ([401 => false, 429 => true, 503 => true] as $status => $retryable) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response(['error' => ['message' => 'private-provider-body']], $status, ['Retry-After' => '2.5'])]);
            try {
                app(AiProviderInterface::class)->health();
                $this->fail('Expected provider rejection');
            } catch (AiProviderException $e) {
                $this->assertSame($retryable, $e->retryable);
                $this->assertSame(3, $e->retryAfter);
                $this->assertStringNotContainsString('private-provider-body', $e->getMessage());
                $this->assertStringNotContainsString('fake-test-key', $e->getMessage());
            }
        }
    }

    public function test_http_failures_keep_safe_status_diagnostics_without_response_body(): void
    {
        foreach ([400 => 'GROQ_BAD_REQUEST', 413 => 'GROQ_REQUEST_TOO_LARGE', 422 => 'GROQ_UNPROCESSABLE_REQUEST', 504 => 'GROQ_REQUEST_TIMEOUT', 503 => 'GROQ_SERVICE_ERROR'] as $status => $code) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response(['error' => ['message' => 'private-body']], $status)]);
            try {
                app(AiProviderInterface::class)->health();
                $this->fail('Expected HTTP rejection');
            } catch (AiProviderException $error) {
                $this->assertSame($code, $error->reason);
                $this->assertSame($status >= 500, $error->retryable);
                $this->assertStringNotContainsString('private-body', $error->getMessage());
                $this->assertSame($code, OperationalSummary::safeCode($code));
                $this->assertSame($error->retryable, OperationalSummary::retryable($code));
            }
        }
    }

    public function test_truncation_and_invalid_json_are_rejected(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"reply":"partial"}']]]])]);
        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('AI_OUTPUT_TRUNCATED');
        app(LocalAiProbe::class)->run('Explica PDF.', 'ro');
    }

    public function test_refusal_and_malformed_json_have_safe_distinct_diagnostics(): void
    {
        foreach ([
            ['message' => ['refusal' => 'private-body', 'content' => null], 'code' => 'AI_RESPONSE_REFUSED'],
            ['message' => ['content' => 'private-body'], 'code' => 'INVALID_AI_RESPONSE'],
        ] as $case) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => $case['message']]]])]);
            try {
                app(LocalAiProbe::class)->run('Example', 'ro');
                $this->fail('Expected rejection');
            } catch (AiProviderException $error) {
                $this->assertSame($case['code'], $error->reason);
                $this->assertFalse($error->retryable);
                $this->assertStringNotContainsString('private-body', $error->getMessage());
            }
        }
    }
}
