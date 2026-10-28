<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('document_id')->unique();
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('due_on');
            $table->string('locale', 5);
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('cycle')->default(1);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status', 'due_on']);
        });
        Schema::create('reminder_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reminder_id')->constrained('document_reminders')->cascadeOnDelete();
            $table->unsignedInteger('cycle');
            $table->unsignedTinyInteger('days_before');
            $table->timestamp('scheduled_for');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('in_app_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('failure_code', 50)->nullable();
            $table->timestamps();
            $table->unique(['reminder_id', 'cycle', 'days_before']);
            $table->index(['status', 'scheduled_for']);
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('reminder_deliveries');
        Schema::dropIfExists('document_reminders');
    }
};
