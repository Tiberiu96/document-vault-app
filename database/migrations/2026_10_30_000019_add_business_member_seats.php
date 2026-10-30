<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_plan_limits', fn (Blueprint $table) => $table->unsignedInteger('extra_member_price_bani')->nullable());
        Schema::table('workspaces', fn (Blueprint $table) => $table->unsignedInteger('additional_member_seats')->default(0));
    }

    public function down(): void
    {
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn('additional_member_seats'));
        Schema::table('platform_plan_limits', fn (Blueprint $table) => $table->dropColumn('extra_member_price_bani'));
    }
};
