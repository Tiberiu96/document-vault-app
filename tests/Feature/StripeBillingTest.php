<?php

namespace Tests\Feature;

use App\Contracts\Billing\StripeBillingGateway;
use App\Models\BillingCheckoutAttempt;
use App\Models\PlatformPlanPrice;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\ProcessStripeEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PragmaRX\Google2FA\Google2FA;
use Stripe\WebhookSignature;
use Tests\TestCase;

class StripeBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['services.stripe.enabled' => true, 'services.stripe.environment' => 'sandbox', 'services.stripe.secret' => 'sk_test_example', 'services.stripe.webhook_secret' => 'whsec_example']);
        DB::table('platform_plan_limits')->where('plan', 'pro')->update(['enabled' => true]);
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::forceCreate(['owner_id' => $this->owner->id, 'type' => 'business', 'name' => 'Billing business']);
        $this->workspace->members()->attach($this->owner, ['role' => 'owner']);
        PlatformPlanPrice::create(['workspace_type' => 'business', 'currency' => 'RON', 'plan_amount_minor' => 5000, 'extra_seat_amount_minor' => 1200, 'enabled' => true]);
        PlatformPlanPrice::create(['workspace_type' => 'business', 'currency' => 'EUR', 'plan_amount_minor' => 3000, 'extra_seat_amount_minor' => 700, 'enabled' => true]);
        $this->actingAs($this->owner)->withSession(['active_workspace_id' => $this->workspace->id]);
    }

    public function test_owner_sees_server_calculated_summary_and_checkout_uses_the_same_amounts(): void
    {
        $gateway = new class implements StripeBillingGateway
        {
            public array $parameters = [];

            public function createSubscriptionCheckout(array $parameters, string $idempotencyKey): array
            {
                $this->parameters = $parameters;

                return ['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.test/session', 'expires_at' => now()->addMinutes(30)->timestamp];
            }
        };
        $this->app->instance(StripeBillingGateway::class, $gateway);

        $this->get('/ro/abonament/sumar?currency=RON&additional_member_seats=2')->assertOk()->assertSee('74,00 RON')->assertSee('12,00 RON');
        $this->post('/ro/abonament/checkout', ['currency' => 'RON', 'additional_member_seats' => 2])->assertRedirect('https://checkout.stripe.test/session');

        $attempt = BillingCheckoutAttempt::firstOrFail();
        $this->assertSame(7400, $attempt->total_amount_minor);
        $this->assertSame(5000, $gateway->parameters['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame(1200, $gateway->parameters['line_items'][1]['price_data']['unit_amount']);
        $this->assertSame(2, $gateway->parameters['line_items'][1]['quantity']);
        $this->assertSame((string) $this->workspace->id, $gateway->parameters['subscription_data']['metadata']['workspace_id']);
        $this->get('/ro/abonament/sumar?currency=EUR&additional_member_seats=0')->assertOk()->assertSee('30,00 EUR');
    }

    public function test_non_owner_and_another_workspace_cannot_start_billing(): void
    {
        $member = User::factory()->create();
        $this->workspace->members()->attach($member, ['role' => 'admin']);
        $this->actingAs($member)->withSession(['active_workspace_id' => $this->workspace->id])
            ->get('/ro/abonament/sumar?currency=RON')->assertForbidden();

        $otherOwner = User::factory()->create();
        $other = Workspace::forceCreate(['owner_id' => $otherOwner->id, 'type' => 'business', 'name' => 'Other']);
        $other->members()->attach($otherOwner, ['role' => 'owner']);
        $this->actingAs($this->owner)->withSession(['active_workspace_id' => $other->id])
            ->get('/ro/abonament/sumar?currency=RON')->assertForbidden();
    }

    public function test_signed_webhook_is_stored_once_and_authoritatively_activates_then_suspends_entitlement(): void
    {
        Queue::fake();
        $attempt = BillingCheckoutAttempt::create([
            'workspace_id' => $this->workspace->id, 'requested_by' => $this->owner->id, 'workspace_type' => 'business', 'currency' => 'RON',
            'plan_amount_minor' => 5000, 'extra_seat_amount_minor' => 1200, 'additional_member_seats' => 2, 'total_amount_minor' => 7400,
            'idempotency_key' => 'checkout_test', 'provider_session_id' => 'cs_test_123', 'status' => 'pending', 'expires_at' => now()->addHour(),
        ]);
        $payload = $this->event('evt_checkout', 'checkout.session.completed', 100, [
            'id' => 'cs_test_123', 'currency' => 'ron', 'amount_total' => 7400, 'payment_status' => 'paid', 'customer' => 'cus_test', 'subscription' => 'sub_test',
            'metadata' => ['checkout_attempt_id' => $attempt->id, 'workspace_id' => (string) $this->workspace->id],
        ]);
        $signature = WebhookSignature::generateSignatureHeader($payload, 'whsec_example');
        $this->postWebhook($payload, $signature)->assertOk();
        $this->postWebhook($payload, $signature)->assertOk();
        $this->assertDatabaseCount('stripe_webhook_events', 1);
        app(ProcessStripeEvent::class)->handle(StripeWebhookEvent::firstOrFail());
        $this->assertSame('pro', $this->workspace->fresh()->plan_code);
        $this->assertSame(2, $this->workspace->fresh()->additional_member_seats);

        $failed = StripeWebhookEvent::create(['environment' => 'sandbox', 'stripe_event_id' => 'evt_failed', 'event_type' => 'invoice.payment_failed', 'provider_created_at' => 101,
            'payload' => json_decode($this->event('evt_failed', 'invoice.payment_failed', 101, ['subscription' => 'sub_test']), true), 'status' => 'queued']);
        app(ProcessStripeEvent::class)->handle($failed);
        $this->assertSame('free', $this->workspace->fresh()->plan_code);
        $this->assertSame(0, $this->workspace->fresh()->additional_member_seats);

        $old = StripeWebhookEvent::create(['environment' => 'sandbox', 'stripe_event_id' => 'evt_old_paid', 'event_type' => 'invoice.paid', 'provider_created_at' => 99,
            'payload' => json_decode($this->event('evt_old_paid', 'invoice.paid', 99, ['subscription' => 'sub_test']), true), 'status' => 'queued']);
        app(ProcessStripeEvent::class)->handle($old);
        $this->assertSame('free', $this->workspace->fresh()->plan_code);
    }

    public function test_invalid_signature_does_not_persist_payload(): void
    {
        $this->postWebhook('{}', 'invalid')->assertBadRequest();
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    public function test_admin_mfa_is_required_to_version_commercial_prices(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true, 'admin_mfa_secret' => (new Google2FA)->generateSecretKey(32), 'admin_mfa_confirmed_at' => now(), 'admin_mfa_version' => 1])->save();
        $payload = ['workspace_type' => 'personal', 'currency' => 'HUF', 'plan_amount_minor' => 9900, 'version' => 0, 'enabled' => 1];
        $baseSession = ['admin' => ['password_at' => now()->timestamp, 'password_fingerprint' => hash('sha256', $admin->password), 'last_seen' => now()->timestamp, 'mfa' => []]];
        $this->actingAs($admin, 'admin')->withSession($baseSession)->patch('/admin/preturi-abonamente', $payload)->assertRedirect('/admin/mfa/verificare');
        $this->withSession(['admin' => ['password_at' => now()->timestamp, 'password_fingerprint' => hash('sha256', $admin->password), 'last_seen' => now()->timestamp,
            'mfa' => ['user_id' => $admin->id, 'version' => 1, 'at' => now()->timestamp]]])
            ->patch('/admin/preturi-abonamente', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('platform_plan_prices', ['workspace_type' => 'personal', 'currency' => 'HUF', 'plan_amount_minor' => 9900, 'enabled' => true, 'version' => 1]);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'billing.price_updated']);
    }

    private function event(string $id, string $type, int $created, array $object): string
    {
        return json_encode(['id' => $id, 'object' => 'event', 'api_version' => '2026-09-30.endive', 'created' => $created, 'livemode' => false, 'type' => $type, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
    }

    private function postWebhook(string $payload, string $signature)
    {
        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $payload);
    }
}
