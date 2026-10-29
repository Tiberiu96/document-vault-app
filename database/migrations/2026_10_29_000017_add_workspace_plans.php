<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_plan_limits', fn (Blueprint $table) => $table->boolean('enabled')->default(true));
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('plan_code', 30)->default('free');
            $table->timestamp('plan_expires_at')->nullable();
            $table->unsignedInteger('plan_version')->default(0);
        });
        // Sablon conservator, fara pret sau activare comerciala implicita.
        DB::table('platform_plan_limits')->insert([
            'plan' => 'pro', 'enabled' => false, 'max_pages' => 10, 'max_file_bytes' => 5242880,
            'storage_bytes' => 104857600, 'monthly_ocr_pages' => 50, 'monthly_ai_calls' => 10,
            'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('platform_plan_limits')->where('plan', 'pro')->delete();
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn(['plan_code', 'plan_expires_at', 'plan_version']));
        Schema::table('platform_plan_limits', fn (Blueprint $table) => $table->dropColumn('enabled'));
    }
};
