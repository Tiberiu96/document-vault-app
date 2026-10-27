<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoundationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_available_and_not_indexable(): void
    {
        $this->get('/up')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_horizon_is_closed_to_guests_even_in_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->get('/admin/horizon')->assertForbidden();
        $this->get('/horizon')->assertNotFound();
    }

    public function test_horizon_is_closed_to_a_super_admin_until_mfa_is_implemented(): void
    {
        $user = User::factory()->create();
        $user->is_super_admin = true;
        $user->save();

        $this->actingAs($user)->get('/admin/horizon')->assertForbidden();
        $this->assertSame('ro', app()->getLocale());
    }

    public function test_private_storage_does_not_register_file_serving_routes(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->get('/storage/private-example.pdf')->assertNotFound();
    }

    public function test_api_documentation_is_not_exposed_automatically(): void
    {
        foreach (['/docs', '/docs.openapi', '/docs.postman'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_all_worker_timeouts_are_below_redis_retry_after(): void
    {
        $queues = [];
        foreach (config('horizon.defaults') as $supervisor) {
            $this->assertLessThan(config('queue.connections.redis.retry_after'), $supervisor['timeout']);
            array_push($queues, ...$supervisor['queue']);
        }
        $this->assertSame(['high', 'default', 'ocr', 'ai', 'low'], $queues);
        $this->assertTrue(config('queue.connections.redis.after_commit'));
    }
}
