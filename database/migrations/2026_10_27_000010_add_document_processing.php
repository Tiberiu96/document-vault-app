<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedInteger('metadata_version')->default(0);
            $table->boolean('ai_requested')->default(false);
            $table->string('ocr_status', 20)->default('idle');
            $table->string('ai_status', 20)->default('idle');
            $table->uuid('ocr_run_id')->nullable();
            $table->uuid('ai_run_id')->nullable();
            $table->longText('ocr_text')->nullable();
            $table->string('ocr_engine', 30)->nullable();
        });
        Schema::create('processing_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->unsignedInteger('ocr_reserved')->default(0);
            $table->unsignedInteger('ocr_used')->default(0);
            $table->unsignedInteger('ai_reserved')->default(0);
            $table->unsignedInteger('ai_used')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'period']);
        });
        Schema::create('processing_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('document_id');
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('status', 20)->default('queued');
            $table->date('period');
            $table->unsignedInteger('units');
            $table->unsignedInteger('base_version');
            $table->string('locale', 8);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_code', 60)->nullable();
            $table->longText('result')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_runs');
        Schema::dropIfExists('processing_usages');
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn(['metadata_version', 'ai_requested', 'ocr_status', 'ai_status', 'ocr_run_id', 'ai_run_id', 'ocr_text', 'ocr_engine']));
    }
};
