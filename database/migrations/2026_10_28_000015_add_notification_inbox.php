<?php

use App\Notifications\DocumentReminder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->json('notification_preferences')->nullable());
        Schema::table('notifications', function (Blueprint $t) {
            $t->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $t->index(['notifiable_type', 'notifiable_id', 'workspace_id', 'read_at'], 'notifications_inbox_index');
        });
        DB::table('notifications')->where('type', DocumentReminder::class)->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $data = json_decode($row->data, true);
                $reminder = DB::table('document_reminders')->where('id', $data['reminder_id'] ?? '')->first();
                if (! $reminder || $reminder->user_id !== (int) $row->notifiable_id) {
                    continue;
                }
                $delivery = DB::table('reminder_deliveries')->where('id', $row->id)->where('reminder_id', $reminder->id)->first();
                $data['cycle'] = $delivery?->cycle;
                DB::table('notifications')->where('id', $row->id)->update(['workspace_id' => $reminder->workspace_id, 'data' => json_encode($data)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $t) {
            $t->dropIndex('notifications_inbox_index');
            $t->dropConstrainedForeignId('workspace_id');
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notification_preferences'));
    }
};
