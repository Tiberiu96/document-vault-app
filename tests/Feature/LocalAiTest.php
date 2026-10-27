<?php

namespace Tests\Feature;

use App\Contracts\Ai\AiProviderInterface;
use App\Jobs\Ai\RunLocalAiProbe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\LocalAiProbe;
use Database\Seeders\LanguageSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LocalAiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LanguageSeeder::class);
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'local');
        config(['ai.enabled' => true, 'ai.provider' => 'ollama', 'ai.ollama.model' => 'qwen3.5:4b']);
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->workspace = new Workspace;
        $this->workspace->forceFill(['owner_id' => $this->user->id, 'type' => 'personal', 'name' => 'AI example workspace'])->save();
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);
        $this->actingAs($this->user)->withSession(['active_workspace_id' => $this->workspace->id]);
    }

    private function fakeOllama(string $content = '{"reply":"Raspuns local de test."}'): void
    {
        Http::fake([
            '127.0.0.1:11434/api/version' => Http::response(['version' => '0.40.11']),
            '127.0.0.1:11434/api/tags' => Http::response(['models' => [['name' => 'qwen3.5:4b']]]),
            '127.0.0.1:11434/api/chat' => Http::response(['message' => ['content' => $content], 'done' => true, 'done_reason' => 'stop']),
        ]);
    }

    public function test_playground_and_every_action_are_404_in_production_for_every_locale(): void
    {
        $this->app->instance('env', 'production');
        foreach (['ro/test-ai', 'en/local-ai-test', 'hu/helyi-ai-teszt', 'bg/test-ai'] as $url) {
            $this->get('/'.$url)->assertNotFound();
            $this->post('/'.$url, ['prompt' => 'Example'])->assertNotFound();
            $this->get('/'.$url.'/11111111-1111-4111-8111-111111111111')->assertNotFound();
        }
        Http::assertNothingSent();
    }

    public function test_local_playground_is_private_and_does_not_make_implicit_ai_calls(): void
    {
        $this->get('/ro/test-ai')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('qwen3.5:4b');
        Http::assertNothingSent();
    }

    public function test_local_request_is_encrypted_queued_on_ai_and_results_are_user_scoped(): void
    {
        Queue::fake();
        $this->fakeOllama();
        $response = $this->post('/ro/test-ai', ['prompt' => 'Explica PDF pe scurt.'])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $run = $query['run'];
        Queue::assertPushedOn('ai', RunLocalAiProbe::class, fn ($job) => $job instanceof ShouldBeEncrypted);
        $job = new RunLocalAiProbe($run, $this->user->id, $this->workspace->id, 'Explica PDF pe scurt.', 'ro');
        $cached = Cache::get($job->key());
        $this->assertIsString($cached);
        $this->assertStringNotContainsString('pending', $cached);
        $job->handle(app(LocalAiProbe::class));
        $this->get('/ro/test-ai/'.$run)->assertOk()->assertJson(['status' => 'ready', 'reply' => 'Raspuns local de test.']);
        $other = User::factory()->create();
        $this->workspace->members()->attach($other->id, ['role' => 'admin']);
        // Workspace personal ramane privat chiar si cu un membership invalid.
        $this->actingAs($other)->get('/ro/test-ai/'.$run)->assertForbidden();
    }

    public function test_disabled_or_unsafe_provider_never_sends_http_requests(): void
    {
        foreach ([['ai.enabled' => false], ['ai.enabled' => true, 'ai.ollama.base_url' => 'https://example.invalid'], ['ai.ollama.base_url' => 'http://127.0.0.1:11434', 'ai.ollama.model' => 'qwen:cloud']] as $values) {
            config($values);
            try {
                app(AiProviderInterface::class)->health();
                $this->fail('Expected AI configuration rejection.');
            } catch (AiProviderException $error) {
                $this->assertFalse($error->retryable);
            }
        }
        Http::assertNothingSent();
    }

    public function test_provider_health_and_schema_are_explicit_and_validated(): void
    {
        $this->fakeOllama();
        $this->assertTrue(app(AiProviderInterface::class)->health()['installed']);
        $reply = app(LocalAiProbe::class)->run('Explica PDF.', 'ro');
        $this->assertSame('Raspuns local de test.', $reply);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/chat') && $request['stream'] === false && $request['think'] === false && $request['format']['type'] === 'object');
    }

    public function test_invalid_structured_output_is_rejected_without_logging_raw_content(): void
    {
        $this->fakeOllama('{"unexpected":"example-only"}');
        try {
            app(LocalAiProbe::class)->run('Example', 'ro');
            $this->fail('Expected response rejection.');
        } catch (AiProviderException $error) {
            $this->assertSame('INVALID_AI_RESPONSE', $error->reason);
            $this->assertStringNotContainsString('example-only', $error->getMessage());
        }
    }

    public function test_rate_limit_and_revoked_access_do_not_call_provider(): void
    {
        Queue::fake();
        for ($i = 0; $i < 3; $i++) {
            $this->post('/ro/test-ai', ['prompt' => 'Example'])->assertRedirect();
        }
        $this->post('/en/local-ai-test', ['prompt' => 'Example'])->assertStatus(429);
        $this->workspace->members()->detach($this->user);
        $job = new RunLocalAiProbe('11111111-1111-4111-8111-111111111111', $this->user->id, $this->workspace->id, 'Example', 'ro');
        $job->handle(app(LocalAiProbe::class));
        Http::assertNothingSent();
    }
}
