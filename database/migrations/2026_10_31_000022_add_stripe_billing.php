<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->string('workspace_type', 16);
            $table->char('currency', 3);
            $table->unsignedBigInteger('plan_amount_minor');
            $table->unsignedBigInteger('extra_seat_amount_minor')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['workspace_type', 'currency']);
        });

        Schema::create('workspace_billing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 24)->default('stripe');
            $table->string('environment', 16);
            $table->string('provider_customer_id')->nullable()->index();
            $table->string('provider_subscription_id')->nullable()->unique();
            $table->string('status', 32)->default('incomplete');
            $table->char('currency', 3);
            $table->unsignedInteger('additional_member_seats')->default(0);
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->unsignedBigInteger('provider_event_created_at')->default(0);
            $table->timestamps();
        });

        Schema::create('billing_checkout_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('workspace_type', 16);
            $table->char('currency', 3);
            $table->unsignedBigInteger('plan_amount_minor');
            $table->unsignedBigInteger('extra_seat_amount_minor')->nullable();
            $table->unsignedInteger('additional_member_seats')->default(0);
            $table->unsignedBigInteger('total_amount_minor');
            $table->string('idempotency_key')->unique();
            $table->string('provider_session_id')->nullable()->unique();
            $table->string('status', 24)->default('created');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 16);
            $table->string('stripe_event_id');
            $table->string('event_type');
            $table->unsignedBigInteger('provider_created_at');
            $table->longText('payload');
            $table->string('status', 24)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error_code', 80)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['environment', 'stripe_event_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
        Schema::dropIfExists('billing_checkout_attempts');
        Schema::dropIfExists('workspace_billing_subscriptions');
        Schema::dropIfExists('platform_plan_prices');
    }
};
