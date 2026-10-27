<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processing_runs', function (Blueprint $table) {
            $table->string('provider', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('processing_runs', fn (Blueprint $table) => $table->dropColumn('provider'));
    }
};
