<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_usage', function (Blueprint $table) {
            $table->unsignedInteger('input_tokens')->nullable()->default(null)->change();
            $table->unsignedInteger('output_tokens')->nullable()->default(null)->change();
            $table->string('provider', 50)->nullable()->after('workspace_id');
            $table->string('model', 150)->nullable()->after('provider');
            $table->string('status', 20)->default('unknown')->after('model');
            $table->index('created_at', 'assistant_usage_created_at_index');
        });

        DB::table('assistant_usage')
            ->where('input_tokens', 0)
            ->where('output_tokens', 0)
            ->update(['input_tokens' => null, 'output_tokens' => null]);
    }

    public function down(): void
    {
        DB::table('assistant_usage')->whereNull('input_tokens')->update(['input_tokens' => 0]);
        DB::table('assistant_usage')->whereNull('output_tokens')->update(['output_tokens' => 0]);

        Schema::table('assistant_usage', function (Blueprint $table) {
            $table->dropIndex('assistant_usage_created_at_index');
            $table->dropColumn(['provider', 'model', 'status']);
            $table->unsignedInteger('input_tokens')->default(0)->nullable(false)->change();
            $table->unsignedInteger('output_tokens')->default(0)->nullable(false)->change();
        });
    }
};
