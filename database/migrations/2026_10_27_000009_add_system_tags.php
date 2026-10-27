<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_id')->nullable()->change();
            $table->string('system_key', 40)->nullable()->unique();
        });
    }

    public function down(): void
    {
        // Nu elimina date globale implicit la rollback; inverseaza numai dupa export/control.
        if (DB::table('tags')->whereNull('workspace_id')->exists()) {
            throw new RuntimeException('System tags must be explicitly removed before rollback.');
        }
        Schema::table('tags', function (Blueprint $table) {
            $table->dropUnique(['system_key']);
            $table->dropColumn('system_key');
            $table->unsignedBigInteger('workspace_id')->nullable(false)->change();
        });
    }
};
