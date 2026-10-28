<?php

namespace Tests\Feature;

use App\Jobs\DeliverReminder;
use App\Livewire\NotificationBell;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AccountMail;
use App\Notifications\WorkspaceAlert;
use App\Services\ReminderService;
use App\Services\WorkspaceAlerts;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Bucharest'));
        Http::preventStrayRequests();
        Queue::fake();
        config(['reminders.enabled' => true, 'reminders.email_enabled' => true, 'mail.default' => 'array']);
        $this->user = User::factory()->create();
        $this->workspace = Workspace::forceCreate(['owner_id' => $this->user->id, 'type' => 'personal', 'name' => 'Test inbox']);
        $this->workspace->members()->attach($this->user, ['role' => 'owner']);
        $this->document = Document::forceCreate(['workspace_id' => $this->workspace->id, 'uploaded_by' => $this->user->id, 'title' => 'Test guarantee', 'original_name' => 'private-original.pdf', 'storage_path' => 'PRIVATE-STORAGE-PATH', 'mime_type' => 'application/pdf', 'size_bytes' => 100, 'page_count' => 1, 'sha256' => hash('sha256', 'fake'), 'status' => 'stored', 'expires_on' => '2026-10-25']);
        $this->actingAs($this->user, 'web')->withSession(['active_workspace_id' => $this->workspace->id]);
    }

    private function send(): array
    {
        $reminder = app(ReminderService::class)->syncDocument($this->document->fresh(), $this->user, 'ro');
        $delivery = $reminder->deliveries()->orderBy('scheduled_for')->first();
        $this->travelTo($delivery->scheduled_for->toImmutable()->addMinute());
        (new DeliverReminder($delivery->id))->handle();

        return [$reminder->fresh(), $delivery->fresh()];
    }

    public function test_reminder_appears_once_and_opening_dropdown_does_not_read_or_resolve_it(): void
    {
        [$reminder, $delivery] = $this->send();
        (new DeliverReminder($delivery->id))->handle();
        $this->assertSame(1, $this->user->notifications()->count());
        $this->assertDatabaseHas('notifications', ['id' => $delivery->id, 'workspace_id' => $this->workspace->id, 'read_at' => null]);
        Livewire::actingAs($this->user)->test(NotificationBell::class, ['workspaceId' => $this->workspace->id])->assertSee('Test guarantee')->assertViewHas('unread', 1)->call('refreshInbox')->assertViewHas('unread', 1);
        $this->assertSame('active', $reminder->fresh()->status);
        $this->get('/ro/notificari')->assertOk()->assertSee('Test guarantee')->assertSee('Marcheaza citit')->assertDontSee('PRIVATE-STORAGE-PATH')->assertDontSee('private-original.pdf');
    }

    public function test_marking_read_updates_badge_but_keeps_history_and_future_deliveries(): void
    {
        [$reminder, $delivery] = $this->send();
        Livewire::actingAs($this->user)->test(NotificationBell::class, ['workspaceId' => $this->workspace->id])->call('markRead', $delivery->id)->assertViewHas('unread', 0);
        $this->assertNotNull($this->user->notifications()->find($delivery->id)->read_at);
        $this->assertSame('active', $reminder->fresh()->status);
        $this->assertSame(2, $reminder->deliveries()->where('status', 'pending')->count());
        $this->get('/ro/notificari')->assertSee('Test guarantee');
        $this->get('/ro/notificari?filter=unread')->assertViewHas('items', fn ($items) => $items->isEmpty())->assertSee('Esti la zi.');
    }

    public function test_open_notification_marks_read_and_redirects_only_to_authorized_internal_document(): void
    {
        [, $delivery] = $this->send();
        $this->patch('/ro/notificari/'.$delivery->id, ['action' => 'open', 'url' => 'https://evil.example'])->assertRedirect('/ro/documente/'.$this->document->id.'#reminders');
        $this->assertNotNull($this->user->notifications()->find($delivery->id)->read_at);
    }

    public function test_completion_stops_future_sends_but_remains_in_history(): void
    {
        [$reminder, $delivery] = $this->send();
        $pending = $reminder->deliveries()->where('status', 'pending')->first();
        $this->patch('/ro/notificari/'.$delivery->id, ['action' => 'complete'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $reminder->fresh()->status);
        $this->assertSame(0, $reminder->deliveries()->whereIn('status', ['pending', 'processing'])->count());
        $this->travelTo($pending->scheduled_for->toImmutable()->addMinute());
        (new DeliverReminder($pending->id))->handle();
        $this->assertSame(1, $this->user->notifications()->count());
        $this->get('/ro/notificari')->assertSee('Reminder inchis');
    }

    public function test_old_notification_cannot_complete_a_renewed_cycle(): void
    {
        [$reminder, $delivery] = $this->send();
        app(ReminderService::class)->change($this->user, $reminder, 'renew', '2026-11-25', $reminder->cycle);
        $this->patch('/ro/notificari/'.$delivery->id, ['action' => 'complete'])->assertSessionHasErrors('reminder');
        $this->assertSame('active', $reminder->fresh()->status);
    }

    public function test_snooze_and_disable_use_the_existing_reminder_state_machine(): void
    {
        [$reminder, $delivery] = $this->send();
        $this->patch('/ro/notificari/'.$delivery->id, ['action' => 'snooze', 'date' => '2026-10-18'])->assertSessionHasNoErrors();
        $this->assertSame(2, $reminder->fresh()->cycle);
        $this->assertNotNull($reminder->fresh()->snoozed_until);
        $this->assertSame(0, $reminder->deliveries()->where('cycle', 1)->where('status', 'pending')->count());
        $next = $reminder->deliveries()->where('cycle', 2)->orderBy('scheduled_for')->first();
        $this->travelTo($next->scheduled_for->toImmutable()->addMinute());
        (new DeliverReminder($next->id))->handle();
        $this->patch('/ro/notificari/'.$next->id, ['action' => 'disable'])->assertSessionHasNoErrors();
        $this->assertSame('disabled', $reminder->fresh()->status);
    }

    public function test_foreign_users_workspaces_and_removed_members_cannot_read_or_mutate_notifications(): void
    {
        [, $delivery] = $this->send();
        $other = User::factory()->create();
        $foreign = Workspace::forceCreate(['owner_id' => $other->id, 'type' => 'personal', 'name' => 'Foreign']);
        $foreign->members()->attach($other, ['role' => 'owner']);
        $this->actingAs($other)->withSession(['active_workspace_id' => $foreign->id]);
        $this->get('/ro/notificari')->assertOk()->assertDontSee('Test guarantee');
        $this->patch('/ro/notificari/'.$delivery->id, ['action' => 'read'])->assertNotFound();
        Livewire::actingAs($other)->test(NotificationBell::class, ['workspaceId' => $foreign->id])->call('markRead', $delivery->id)->assertNotFound();
        Livewire::actingAs($other)->test(NotificationBell::class, ['workspaceId' => $this->workspace->id])->assertStatus(409);
        $this->actingAs($this->user)->withSession(['active_workspace_id' => $this->workspace->id]);
        $this->workspace->members()->detach($this->user);
        $this->get('/ro/notificari')->assertForbidden();
    }

    public function test_deleted_documents_disappear_from_inbox_without_exposing_metadata(): void
    {
        [, $delivery] = $this->send();
        $this->document->delete();
        $this->get('/ro/notificari')->assertDontSee('Test guarantee');
        $this->patch('/ro/notificari/'.$delivery->id, ['action' => 'open'])->assertNotFound();
    }

    public function test_preferences_disable_only_selected_channels_and_never_security_mail(): void
    {
        $preferences = ['reminders_in_app' => 0, 'reminders_email' => 1, 'processing_in_app' => 0, 'usage_in_app' => 1];
        $this->patch('/ro/notificari/preferinte', $preferences)->assertSessionHasNoErrors();
        [, $delivery] = $this->send();
        $this->assertSame(0, $this->user->notifications()->count());
        $this->assertNull($delivery->in_app_at);
        $this->assertNotNull($delivery->email_sent_at);
        $this->patch('/ro/notificari/preferinte', [...$preferences, 'reminders_email' => 0])->assertSessionHasNoErrors();
        $notification = new AccountMail('verify');
        $this->assertSame(['mail'], $notification->via($this->user));
    }

    public function test_both_disabled_channels_skip_delivery_without_recording_a_false_success(): void
    {
        $this->user->forceFill(['notification_preferences' => ['reminders_in_app' => false, 'reminders_email' => false]])->save();
        [, $delivery] = $this->send();
        $this->assertSame('skipped', $delivery->status);
        $this->assertNull($delivery->email_sent_at);
        $this->assertNull($delivery->in_app_at);
        $this->assertSame(0, $this->user->notifications()->count());
    }

    public function test_storage_warning_is_deduplicated_and_omitted_below_threshold(): void
    {
        config(['vault.plans.free.storage_bytes' => 1000]);
        app(WorkspaceAlerts::class)->storage($this->workspace, $this->user);
        $this->assertSame(0, $this->user->notifications()->count());
        $this->workspace->forceFill(['storage_used_bytes' => 900])->save();
        app(WorkspaceAlerts::class)->storage($this->workspace, $this->user);
        app(WorkspaceAlerts::class)->storage($this->workspace, $this->user);
        $this->assertSame(1, $this->user->notifications()->count());
        $this->get('/ro/notificari')->assertSee('Spatiul este aproape plin');
    }

    public function test_processing_failures_notify_once_for_confirmed_documents_only(): void
    {
        $run = ProcessingRun::forceCreate(['workspace_id' => $this->workspace->id, 'document_id' => $this->document->id, 'requested_by' => $this->user->id, 'provider' => 'fake', 'kind' => 'ocr', 'status' => 'failed', 'period' => '2026-10-01', 'units' => 1, 'base_version' => 0, 'locale' => 'ro', 'error_code' => 'PRIVATE-ERROR']);
        app(WorkspaceAlerts::class)->processingFailed($run);
        app(WorkspaceAlerts::class)->processingFailed($run);
        $this->assertSame(1, $this->user->notifications()->count());
        $this->get('/ro/notificari')->assertSee('Procesarea necesita atentie')->assertDontSee('PRIVATE-ERROR');
        $this->document->forceFill(['status' => 'draft'])->save();
        $run->forceFill(['id' => (string) Str::uuid()]);
        app(WorkspaceAlerts::class)->processingFailed($run);
        $this->assertSame(1, $this->user->notifications()->count());
    }

    public function test_read_all_is_scoped_to_user_and_workspace(): void
    {
        $this->send();
        $second = Workspace::forceCreate(['owner_id' => $this->user->id, 'type' => 'business', 'name' => 'Other workspace']);
        $second->members()->attach($this->user, ['role' => 'owner']);
        $this->user->notify(new WorkspaceAlert($second->id, 'storage_near_limit', [], 'other-workspace'));
        $this->post('/ro/notificari/citeste-toate')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->user->unreadNotifications()->count());
        $this->assertSame($second->id, $this->user->unreadNotifications()->sole()->workspace_id);
    }

    public function test_four_locales_render_translated_notifications_and_polling_skips_hidden_tabs(): void
    {
        $this->send();
        foreach (['ro' => 'notificari', 'en' => 'notifications', 'hu' => 'ertesitesek', 'bg' => 'izvestiya'] as $locale => $slug) {
            $this->get('/'.$locale.'/'.$slug)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDontSee('notifications.event_');
        }
        $this->get('/ro/notificari')->assertSee('!document.hidden', false)->assertSee('30000', false);

    }

    public function test_browser_cannot_replace_the_locked_workspace(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->user)->test(NotificationBell::class, ['workspaceId' => $this->workspace->id])->set('workspaceId', 999);
    }

    public function test_livewire_endpoint_and_notification_page_require_verified_web_authentication(): void
    {
        Auth::guard('web')->logoutCurrentDevice();
        $this->get('/ro/notificari')->assertRedirect('/ro/autentificare');
        $uri = app(HandleRequests::class)->getUpdateUri();
        $this->postJson($uri, ['components' => []], ['X-Livewire' => 'true'])->assertUnauthorized();
        $this->user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->user)->postJson($uri, ['components' => []], ['X-Livewire' => 'true'])->assertForbidden();
    }

    public function test_polling_has_a_server_side_rate_limit(): void
    {
        $component = Livewire::actingAs($this->user)->test(NotificationBell::class, ['workspaceId' => $this->workspace->id]);
        for ($i = 0; $i < 30; $i++) {
            $component->call('refreshInbox')->assertOk();
        }
        $component->call('refreshInbox')->assertStatus(429);
    }
}
