<?php

namespace Tests\Feature;

use App\Jobs\DeliverReminder;
use App\Models\Document;
use App\Models\Language;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Documents\DocumentLibrary;
use App\Services\ReminderService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class ReminderTest extends TestCase
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
        Queue::fake();
        Storage::fake('local');
        config(['mail.default' => 'array', 'reminders.enabled' => true, 'reminders.email_enabled' => true, 'app.url' => 'https://vaulty.example']);
        $this->user = User::factory()->create();
        $this->workspace = new Workspace;
        $this->workspace->forceFill(['owner_id' => $this->user->id, 'type' => 'personal', 'name' => 'Reminder test'])->save();
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);
        $this->document = app(DocumentLibrary::class)->upload($this->user, $this->workspace, UploadedFile::fake()->image('reminder.png'), ['title' => 'Example reminder', 'expires_on' => '2026-10-25']);
        $this->actingAs($this->user)->withSession(['active_workspace_id' => $this->workspace->id]);
    }

    private function reminder(string $locale = 'ro'): Reminder
    {
        return app(ReminderService::class)->syncDocument($this->document->fresh(), $this->user, $locale);
    }

    private function due(Reminder $reminder): ReminderDelivery
    {
        $delivery = $reminder->deliveries()->orderBy('scheduled_for')->firstOrFail();
        $this->travelTo($delivery->scheduled_for->toImmutable()->addMinute());

        return $delivery;
    }

    private function sentCount(): int
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->count();
    }

    public function test_schedule_is_exactly_ten_three_one_days_at_nine_with_dst_and_no_day_zero(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Europe/Bucharest'));
        $this->document->forceFill(['expires_on' => '2026-11-03'])->save();
        $reminder = $this->reminder();
        $slots = $reminder->deliveries()->orderBy('scheduled_for')->get();
        $this->assertSame([10, 3, 1], $slots->pluck('days_before')->all());
        $this->assertSame(['2026-10-24 09:00', '2026-10-31 09:00', '2026-11-02 09:00'], $slots->map(fn ($d) => $d->scheduled_for->timezone('Europe/Bucharest')->format('Y-m-d H:i'))->all());
        $this->assertSame([6, 7, 7], $slots->map(fn ($d) => $d->scheduled_for->hour)->all());
        $this->reminder();
        $this->assertSame(3, ReminderDelivery::count());
    }

    public function test_drafts_missing_past_and_today_dates_do_not_schedule_reminders(): void
    {
        foreach ([null, '2026-09-30', '2026-10-01'] as $date) {
            $this->document->forceFill(['expires_on' => $date])->save();
            $this->assertNull(app(ReminderService::class)->syncDocument($this->document, $this->user, 'ro'));
        }
        $this->document->forceFill(['status' => 'draft', 'expires_on' => '2026-10-25'])->save();
        $this->assertNull(app(ReminderService::class)->syncDocument($this->document, $this->user, 'ro'));
        $this->assertSame(0, ReminderDelivery::count());
    }

    public function test_only_remaining_future_slots_are_created_without_catchup(): void
    {
        $this->document->forceFill(['expires_on' => '2026-10-05'])->save();
        $this->assertSame([3, 1], $this->reminder()->deliveries()->orderBy('scheduled_for')->pluck('days_before')->all());
    }

    public function test_save_schedules_automatically_and_ordinary_edits_do_not_reactivate_completed_reminders(): void
    {
        app(DocumentLibrary::class)->update($this->user, $this->document, ['title' => 'Confirmed', 'expires_on' => '2026-10-25']);
        $reminder = Reminder::sole();
        app(ReminderService::class)->change($this->user, $reminder, 'complete');
        app(DocumentLibrary::class)->update($this->user, $this->document->fresh(), ['title' => 'Edited', 'expires_on' => '2026-10-25']);
        $this->assertSame('completed', $reminder->fresh()->status);
        $this->assertSame(0, $reminder->deliveries()->where('status', 'pending')->count());
    }

    public function test_delivery_is_high_encrypted_and_idempotent_for_in_app_and_email(): void
    {
        $reminder = $this->reminder();
        $delivery = $this->due($reminder);
        $job = new DeliverReminder($delivery->id);
        $this->assertSame('high', $job->queue);
        $this->assertTrue($job->afterCommit);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $job->handle();
        $job->handle();
        $this->assertSame(1, $this->sentCount());
        $this->assertSame(1, $this->user->notifications()->count());
        $this->assertNotNull($delivery->fresh()->email_sent_at);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $mail = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('https://vaulty.example/ro/documente/', $mail->getHtmlBody());
    }

    public function test_queued_or_claimed_delivery_is_stopped_by_completion(): void
    {
        $reminder = $this->reminder();
        $delivery = $this->due($reminder);
        DeliverReminder::dispatch($delivery->id);
        $delivery->forceFill(['status' => 'processing', 'claimed_at' => now()])->save();
        $this->patch('/ro/remindere/'.$reminder->id, ['action' => 'complete', 'cycle' => 1])->assertSessionHasNoErrors()->assertRedirect();
        (new DeliverReminder($delivery->id))->handle();
        $this->assertSame('completed', $reminder->fresh()->status);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertSame(0, $this->sentCount());
        $this->assertSame(0, $this->user->notifications()->count());
    }

    public function test_disable_and_removed_membership_prevent_delivery(): void
    {
        $reminder = $this->reminder();
        $delivery = $this->due($reminder);
        $this->workspace->members()->detach($this->user->id);
        (new DeliverReminder($delivery->id))->handle();
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertSame(0, $this->sentCount());
    }

    public function test_snooze_replaces_old_cycle_and_keeps_later_slots(): void
    {
        $reminder = $this->reminder();
        $old = $reminder->deliveries()->first();
        app(ReminderService::class)->change($this->user, $reminder, 'snooze', '2026-10-20', 1);
        $this->assertSame(2, $reminder->fresh()->cycle);
        $this->assertSame(3, $reminder->deliveries()->where('status', 'cancelled')->count());
        $this->assertSame(['2026-10-20', '2026-10-22', '2026-10-24'], $reminder->deliveries()->where('status', 'pending')->orderBy('scheduled_for')->get()->map(fn ($d) => $d->scheduled_for->toDateString())->all());
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:01', 'Europe/Bucharest'));
        (new DeliverReminder($old->id))->handle();
        $this->assertSame(0, $this->sentCount());
        $this->patch('/ro/remindere/'.$reminder->id, ['action' => 'complete', 'cycle' => 1])->assertSessionHasErrors('reminder');
        $this->assertSame('active', $reminder->fresh()->status);
    }

    public function test_renewal_changes_expiry_and_invalidates_old_deliveries_and_metadata_version(): void
    {
        $reminder = $this->reminder();
        app(ReminderService::class)->change($this->user, $reminder, 'complete');
        app(ReminderService::class)->change($this->user, $reminder, 'renew', '2026-12-01', 1);
        $this->assertSame('2026-12-01', $this->document->fresh()->expires_on->toDateString());
        $this->assertSame(1, $this->document->fresh()->metadata_version);
        $this->assertSame('active', $reminder->fresh()->status);
        $this->assertSame(3, $reminder->deliveries()->where('status', 'pending')->count());
    }

    public function test_invalid_snooze_does_not_change_state(): void
    {
        $reminder = $this->reminder();
        $this->patch('/ro/remindere/'.$reminder->id, ['action' => 'snooze', 'date' => '2026-10-25', 'cycle' => 1])->assertSessionHasErrors('date');
        $this->assertSame(1, $reminder->fresh()->cycle);
    }

    public function test_access_is_scoped_to_workspace_and_accountants_cannot_mutate(): void
    {
        $reminder = $this->reminder();
        $other = User::factory()->create();
        $this->workspace->forceFill(['type' => 'business'])->save();
        $this->workspace->members()->attach($other->id, ['role' => 'accountant']);
        $this->actingAs($other)->get('/ro/remindere')->assertOk();
        $this->patch('/ro/remindere/'.$reminder->id, ['action' => 'complete', 'cycle' => 1])->assertForbidden();
        $workspace = new Workspace;
        $workspace->forceFill(['owner_id' => $other->id, 'name' => 'Other', 'type' => 'personal'])->save();
        $workspace->members()->attach($other->id, ['role' => 'owner']);
        $this->withSession(['active_workspace_id' => $workspace->id])->patch('/ro/remindere/'.$reminder->id, ['action' => 'complete', 'cycle' => 1])->assertNotFound();
        $this->get('/ro/remindere')->assertDontSee($this->document->title);
    }

    public function test_manual_creation_for_existing_document_requires_current_version(): void
    {
        $this->document->forceFill(['expires_on' => null])->save();
        $this->post('/ro/documente/'.$this->document->id.'/remindere', ['date' => '2026-11-01', 'metadata_version' => 4])->assertSessionHasErrors('reminder');
        $this->assertSame(0, Reminder::count());
        $this->post('/ro/documente/'.$this->document->id.'/remindere', ['date' => '2026-11-01', 'metadata_version' => 0])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Reminder::count());
        $this->assertSame('2026-11-01', $this->document->fresh()->expires_on->toDateString());
    }

    public function test_uncertain_smtp_is_not_retried_and_exception_content_is_not_recorded(): void
    {
        $reminder = $this->reminder();
        $delivery = $this->due($reminder);
        Mail::extend('unavailable', fn () => new class extends ArrayTransport
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('Private SMTP detail');
            }
        });
        config(['mail.default' => 'unavailable', 'mail.mailers.unavailable' => ['transport' => 'unavailable']]);
        (new DeliverReminder($delivery->id))->handle();
        (new DeliverReminder($delivery->id))->handle();
        $this->assertSame('uncertain', $delivery->fresh()->status);
        $this->assertSame('MAIL_DELIVERY_UNCERTAIN', $delivery->fresh()->failure_code);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertSame(1, $this->user->notifications()->count());
    }

    public function test_late_scheduler_skips_missed_day_without_mail_and_dispatch_is_configurable(): void
    {
        $reminder = $this->reminder();
        $delivery = $reminder->deliveries()->orderBy('scheduled_for')->first();
        $this->travelTo($delivery->scheduled_for->toImmutable()->addDay());
        (new DeliverReminder($delivery->id))->handle();
        $this->assertSame('skipped', $delivery->fresh()->status);
        $this->assertSame(0, $this->sentCount());
        config(['reminders.enabled' => false]);
        $this->assertSame(0, app(ReminderService::class)->dispatchDue());
    }

    public function test_scheduler_dispatches_due_work_on_high_and_disabled_email_still_delivers_in_app(): void
    {
        $reminder = $this->reminder();
        $delivery = $this->due($reminder);
        $this->artisan('vault:reminders:dispatch')->assertSuccessful();
        Queue::assertPushedOn('high', DeliverReminder::class, fn ($job) => $job->deliveryId === $delivery->id);
        config(['reminders.email_enabled' => false]);
        (new DeliverReminder($delivery->id))->handle();
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->in_app_at);
        $this->assertNull($delivery->fresh()->email_sent_at);
        $this->assertSame(0, $this->sentCount());
    }

    public function test_disable_unverified_recipient_and_document_deletion_suppress_queued_mail(): void
    {
        $reminder = $this->reminder();
        $delivery = $this->due($reminder);
        $this->user->forceFill(['email_verified_at' => null])->save();
        (new DeliverReminder($delivery->id))->handle();
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->user->forceFill(['email_verified_at' => now()])->save();
        app(ReminderService::class)->change($this->user, $reminder, 'disable');
        $this->assertSame('disabled', $reminder->fresh()->status);
        $this->delete('/ro/documente/'.$this->document->id, ['confirm' => 1])->assertRedirect();
        (new DeliverReminder($delivery->id))->handle();
        $this->assertSame(0, $this->sentCount());
        $this->assertSame(0, ReminderDelivery::count());
    }

    public function test_inactive_language_falls_back_and_failed_worker_never_blindly_resends(): void
    {
        $reminder = $this->reminder('en');
        Language::where('locale', 'en')->update(['is_active' => false]);
        $delivery = $this->due($reminder);
        (new DeliverReminder($delivery->id))->handle();
        $mail = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('/ro/documente/', $mail->getHtmlBody());
        $next = $reminder->deliveries()->where('status', 'pending')->orderBy('scheduled_for')->first();
        $next->forceFill(['status' => 'processing', 'claimed_at' => now()->subMinutes(3)])->save();
        app(ReminderService::class)->dispatchDue();
        (new DeliverReminder($next->id))->handle();
        $this->assertSame('uncertain', $next->fresh()->status);
        $this->assertSame(1, $this->sentCount());
    }

    public function test_local_midnight_validation_never_activates_today_even_when_utc_date_is_yesterday(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 00:30', 'Europe/Bucharest'));
        $this->post('/ro/documente/'.$this->document->id.'/remindere', ['date' => '2026-10-02', 'metadata_version' => 0])->assertSessionHasErrors('date');
        $this->assertSame(0, Reminder::count());
    }

    public function test_activation_inherits_existing_expiry_without_asking_or_overwriting_it(): void
    {
        $html = $this->get('/ro/documente/'.$this->document->id)->assertOk()->getContent();
        $panel = explode('id="reminders"', $html, 2)[1];
        $this->assertStringContainsString('25.10.2026', $panel);
        $this->assertStringNotContainsString('name="date"', $panel);
        $this->assertStringNotContainsString('(ora Romaniei)', $panel);
        $this->post('/ro/documente/'.$this->document->id.'/remindere', ['metadata_version' => 0, 'date' => '2026-12-01'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('2026-10-25', Reminder::sole()->due_on->toDateString());
        $this->assertSame('2026-10-25', $this->document->fresh()->expires_on->toDateString());
        $this->assertSame(0, $this->document->fresh()->metadata_version);
    }

    public function test_existing_past_expiry_is_visible_but_never_scheduled_and_missing_date_requires_input(): void
    {
        $this->document->forceFill(['expires_on' => '2026-09-25'])->save();
        $this->get('/ro/documente/'.$this->document->id)->assertOk()->assertSee('25.09.2026')->assertSee(__('reminders.past_note'))->assertDontSee(__('reminders.create'));
        $this->post('/ro/documente/'.$this->document->id.'/remindere', ['metadata_version' => 0])->assertSessionHasErrors('date');
        $this->document->forceFill(['expires_on' => null])->save();
        $this->post('/ro/documente/'.$this->document->id.'/remindere', ['metadata_version' => 0])->assertSessionHasErrors('date');
        $this->assertSame(0, Reminder::count());
    }

    public function test_interface_is_localized_noindex_and_processing_is_last(): void
    {
        $this->reminder();
        foreach (['ro/remindere', 'en/reminders', 'hu/emlekeztetok', 'bg/napomnyaniya'] as $path) {
            $this->get('/'.$path)->assertOk()->assertSee('noindex, nofollow');
        }
        $html = $this->get('/ro/documente/'.$this->document->id)->assertOk()->assertSee('Marcheaza rezolvat')->getContent();
        $this->assertLessThan(strpos($html, 'Procesare document'), strpos($html, 'id="reminders"'));
    }
}
