<?php

namespace Tests\Feature;

use App\Ai\Agents\VaultyAssistant;
use App\Ai\Tools\GetDocument;
use App\Ai\Tools\ListMyDocuments;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LanguageSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LanguageSeeder::class);
        $this->withoutMiddleware(PreventRequestForgery::class);
        config([
            'ai.enabled' => true,
            'assistant.enabled' => true,
            'assistant.provider' => 'ollama',
            'assistant.cache_store' => 'array',
            'assistant.concurrency_wait_seconds' => 0,
        ]);
        Cache::store('array')->clear();
        $this->user = User::factory()->create();
        $this->workspace = $this->workspaceFor($this->user, 'Personal');
        $this->actingAs($this->user)->withSession(['active_workspace_id' => $this->workspace->id]);
    }

    public function test_stream_is_private_sanitized_and_records_usage_without_persisting_messages(): void
    {
        VaultyAssistant::fake(['Raspuns sigur.']);

        $response = $this->postJson('/ro/asistent', ['messages' => [['role' => 'user', 'content' => 'Ce documente am?']]])->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
            ->assertHeader('X-Accel-Buffering', 'no');

        $content = $response->streamedContent();
        $this->assertStringContainsString('"type":"text"', $content);
        $this->assertStringContainsString('Raspuns', $content);
        $this->assertStringContainsString(' sigur.', $content);
        $this->assertStringContainsString('"type":"done"', $content);
        $this->assertDatabaseCount('assistant_usage', 1);
        $this->assertDatabaseMissing('assistant_usage', ['user_id' => 999999]);
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('agent_conversations'));
    }

    public function test_tools_cannot_read_a_foreign_workspace_even_with_an_explicit_document_id(): void
    {
        $mine = $this->document($this->workspace, $this->user, 'Documentul meu');
        $otherUser = User::factory()->create();
        $otherWorkspace = $this->workspaceFor($otherUser, 'Strain');
        $foreign = $this->document($otherWorkspace, $otherUser, 'Secret strain');
        $this->bindToolRequest();

        $list = json_decode((new ListMyDocuments)->handle(new ToolRequest), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$mine->id], array_column($list['documents'], 'id'));

        $this->expectException(ModelNotFoundException::class);
        (new GetDocument)->handle(new ToolRequest(['document_id' => $foreign->id]));
    }

    public function test_daily_user_lock_and_global_concurrency_limits_return_stable_statuses(): void
    {
        VaultyAssistant::fake(['Unu', 'Doi']);
        config(['assistant.daily_messages.free' => 1]);
        $payload = ['messages' => [['role' => 'user', 'content' => 'Salut']]];

        $this->postJson('/ro/asistent', $payload)->assertOk()->streamedContent();
        $this->postJson('/ro/asistent', $payload)->assertStatus(429)->assertJson(['message' => __('assistant.daily_limit')]);

        $dailyKey = 'assistant:daily:'.$this->user->id.':'.now('UTC')->toDateString();
        RateLimiter::clear($dailyKey);
        $userLock = Cache::store('array')->lock('assistant:user:'.$this->user->id, 60);
        $this->assertTrue($userLock->get());
        $this->postJson('/ro/asistent', $payload)->assertStatus(429)->assertJson(['message' => __('assistant.active_response')]);
        $userLock->release();

        config(['assistant.global_concurrency' => 1]);
        $globalLock = Cache::store('array')->lock('assistant:global:1', 60);
        $this->assertTrue($globalLock->get());
        $this->postJson('/ro/asistent', $payload)->assertStatus(503)->assertJson(['message' => __('assistant.busy')]);
        $globalLock->release();
    }

    public function test_guest_unverified_user_and_disabled_feature_cannot_use_assistant(): void
    {
        auth()->logout();
        $this->postJson('/ro/asistent', ['messages' => [['role' => 'user', 'content' => 'Salut']]])->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        $workspace = $this->workspaceFor($unverified, 'Neverificat');
        $this->actingAs($unverified)->withSession(['active_workspace_id' => $workspace->id])
            ->postJson('/ro/asistent', ['messages' => [['role' => 'user', 'content' => 'Salut']]])->assertForbidden();

        config(['assistant.enabled' => false]);
        $this->actingAs($this->user)->withSession(['active_workspace_id' => $this->workspace->id])
            ->postJson('/ro/asistent', ['messages' => [['role' => 'user', 'content' => 'Salut']]])->assertNotFound();
    }

    public function test_agent_exposes_only_the_three_non_destructive_initial_tools(): void
    {
        $tools = collect((new VaultyAssistant)->tools())->map(fn ($tool) => class_basename($tool))->all();

        $this->assertSame(['ListMyDocuments', 'GetDocument', 'CreateReminder'], $tools);
    }

    private function bindToolRequest(): void
    {
        Auth::login($this->user);
        $request = HttpRequest::create('/ro/asistent', 'POST');
        $request->setUserResolver(fn () => $this->user);
        $request->attributes->set('workspace', $this->workspace);
        $this->app->instance('request', $request);
    }

    private function workspaceFor(User $user, string $name): Workspace
    {
        $workspace = new Workspace;
        $workspace->forceFill(['owner_id' => $user->id, 'type' => 'personal', 'name' => $name])->save();
        $workspace->members()->attach($user->id, ['role' => 'owner']);

        return $workspace;
    }

    private function document(Workspace $workspace, User $user, string $title): Document
    {
        $document = new Document;
        $document->forceFill([
            'workspace_id' => $workspace->id, 'uploaded_by' => $user->id, 'title' => $title,
            'original_name' => 'test.pdf', 'storage_path' => 'documents/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 100, 'page_count' => 1,
            'sha256' => hash('sha256', $workspace->id.$title), 'status' => 'stored',
        ])->save();

        return $document;
    }
}
