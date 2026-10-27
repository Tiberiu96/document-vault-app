<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', fn (Blueprint $table) => $table->unsignedBigInteger('storage_used_bytes')->default(0));
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 20)->nullable();
            $table->string('system_key', 40)->nullable();
            $table->string('name', 80)->nullable();
            $table->string('normalized_name', 80)->nullable();
            $table->timestamps();
            $table->unique(['scope', 'system_key']);
            $table->unique(['workspace_id', 'normalized_name']);
        });
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('normalized_name', 40);
            $table->timestamps();
            $table->unique(['workspace_id', 'normalized_name']);
        });
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type', 50);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('page_count');
            $table->char('sha256', 64);
            $table->string('status', 30)->default('stored');
            $table->text('description')->nullable();
            $table->string('supplier', 150)->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamp('purge_requested_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['workspace_id', 'sha256']);
            $table->index(['workspace_id', 'created_at']);
        });
        Schema::create('document_tag', function (Blueprint $table) {
            $table->uuid('document_id');
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['document_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_tag');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('categories');
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn('storage_used_bytes'));
    }
};
