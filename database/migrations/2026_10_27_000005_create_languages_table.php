<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 10)->unique();
            $table->string('display_name');
            $table->string('native_name');
            $table->char('default_currency', 3);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('translation_ready')->default(false);
            $table->unsignedTinyInteger('default_slot')->storedAs('CASE WHEN is_default = 1 THEN 1 ELSE NULL END')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
