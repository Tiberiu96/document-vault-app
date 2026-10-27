<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'member', 'accountant']);
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
            $table->index(['user_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
