<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->enum('type', ['personal', 'business']);
            $table->string('name');
            // NULL pentru business: un owner poate detine mai multe firme.
            $table->unsignedBigInteger('personal_owner_id')
                ->storedAs("CASE WHEN type = 'personal' THEN owner_id ELSE NULL END")
                ->unique();
            $table->timestamps();
            $table->index(['owner_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
