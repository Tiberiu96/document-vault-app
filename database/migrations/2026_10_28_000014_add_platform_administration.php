<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('admin_mfa_secret')->nullable();
            $table->text('admin_recovery_hashes')->nullable();
            $table->timestamp('admin_mfa_confirmed_at')->nullable();
            $table->unsignedBigInteger('admin_mfa_last_counter')->nullable();
            $table->unsignedInteger('admin_mfa_version')->default(0);
        });
        Schema::create('admin_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->string('target_type', 40)->nullable();
            $table->string('target_id', 60)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('platform_plan_limits', function (Blueprint $table) {
            $table->string('plan', 30)->primary();
            $table->unsignedInteger('max_pages');
            $table->unsignedBigInteger('max_file_bytes');
            $table->unsignedBigInteger('storage_bytes');
            $table->integer('monthly_ocr_pages');
            $table->integer('monthly_ai_calls');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::table('categories', fn (Blueprint $table) => $table->json('translations')->nullable());
        Schema::table('tags', fn (Blueprint $table) => $table->json('translations')->nullable());
    }

    public function down(): void
    {
        Schema::table('tags', fn (Blueprint $table) => $table->dropColumn('translations'));
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('translations'));
        Schema::dropIfExists('platform_plan_limits');
        Schema::dropIfExists('admin_audit_events');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['admin_mfa_secret', 'admin_recovery_hashes', 'admin_mfa_confirmed_at', 'admin_mfa_last_counter', 'admin_mfa_version']));
    }
};
