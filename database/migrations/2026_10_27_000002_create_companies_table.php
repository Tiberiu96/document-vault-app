<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->restrictOnDelete();
            $table->string('legal_name');
            $table->string('cui', 32)->index();
            $table->string('registration_number', 64);
            $table->json('registered_address');
            $table->char('country_code', 2)->default('RO');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
