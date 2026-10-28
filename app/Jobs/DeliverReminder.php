<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\DocumentReminder;
use App\Services\LanguageCatalog;
use App\Services\NotificationPreferences;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

class DeliverReminder implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public int $uniqueFor = 120;

    public bool $failOnTimeout = true;

    public function __construct(public string $deliveryId)
    {
        $this->onQueue('high')->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->deliveryId;
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(): void
    {
        if (! config('reminders.enabled')) {
            return;
        }
        $candidate = ReminderDelivery::with('reminder')->find($this->deliveryId);
        if (! $candidate?->reminder) {
            return;
        }
        $workspaceId = $candidate->reminder->workspace_id;
        $claim = DB::transaction(function () use ($workspaceId, $candidate) {
            Workspace::lockForUpdate()->find($workspaceId);
            $document = Document::lockForUpdate()->find($candidate->reminder->document_id);
            $reminder = Reminder::lockForUpdate()->find($candidate->reminder_id);
            $delivery = ReminderDelivery::lockForUpdate()->find($this->deliveryId);
            if (! $delivery || $delivery->status !== 'pending' || $delivery->scheduled_for->isFuture()) {
                return false;
            }
            $user = $reminder ? User::find($reminder->user_id) : null;
            if (! $this->eligible($document, $reminder, $delivery, $user)) {
                $delivery->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();

                return false;
            }
            if ($delivery->scheduled_for->lt(now(config('reminders.timezone'))->startOfDay())) {
                $delivery->forceFill(['status' => 'skipped', 'failure_code' => 'DELIVERY_WINDOW_PASSED', 'finished_at' => now()])->save();

                return false;
            }
            $delivery->forceFill(['status' => 'processing', 'claimed_at' => now(), 'attempts' => $delivery->attempts + 1])->save();

            return true;
        }, 3);
        if (! $claim) {
            return;
        }
        $started = hrtime(true);
        // Fara retry de tranzactie dupa un efect SMTP: acesta nu este tranzactional.
        DB::transaction(function () use ($workspaceId, $candidate, $started) {
            Workspace::lockForUpdate()->find($workspaceId);
            $document = Document::lockForUpdate()->find($candidate->reminder->document_id);
            $reminder = Reminder::lockForUpdate()->find($candidate->reminder_id);
            $delivery = ReminderDelivery::lockForUpdate()->find($this->deliveryId);
            if (! $delivery || $delivery->status !== 'processing') {
                return;
            }
            $user = $reminder ? User::find($reminder->user_id) : null;
            if (! $this->eligible($document, $reminder, $delivery, $user)) {
                $delivery->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();

                return;
            }
            $reminder->setRelation('document', $document);
            $catalog = app(LanguageCatalog::class);
            if (! $catalog->available()->contains('locale', $reminder->locale)) {
                $reminder->locale = $catalog->defaultLocale();
            }
            $notification = new DocumentReminder($reminder, $delivery);
            $preferences = app(NotificationPreferences::class)->forUser($user);
            if ($preferences['reminders_in_app'] && ! $user->notifications()->whereKey($delivery->id)->exists()) {
                Notification::sendNow($user, $notification, ['database']);
            }
            if ($preferences['reminders_in_app']) {
                $delivery->in_app_at = now();
            }
            try {
                if (config('reminders.email_enabled') && $preferences['reminders_email']) {
                    Notification::sendNow($user, $notification, ['mail']);
                    $delivery->email_sent_at = now();
                }
                $delivery->status = $delivery->in_app_at || $delivery->email_sent_at ? 'sent' : 'skipped';
            } catch (\Throwable) {
                $delivery->status = 'uncertain';
                $delivery->failure_code = 'MAIL_DELIVERY_UNCERTAIN';
            }
            $delivery->forceFill(['finished_at' => now(), 'duration_ms' => (int) ((hrtime(true) - $started) / 1000000)])->save();
        });
    }

    private function eligible(?Document $document, ?Reminder $reminder, ReminderDelivery $delivery, ?User $user): bool
    {
        return $document && $document->status === 'stored' && $reminder && $reminder->status === 'active'
            && $reminder->cycle === $delivery->cycle && $document->expires_on?->toDateString() === $reminder->due_on->toDateString()
            && $reminder->due_on->toDateString() > now(config('reminders.timezone'))->toDateString()
            && $user && $user->hasVerifiedEmail() && Gate::forUser($user)->allows('view', $document);
    }

    public function failed(?\Throwable $exception): void
    {
        ReminderDelivery::whereKey($this->deliveryId)->where('status', 'processing')->update(['status' => 'uncertain', 'failure_code' => 'WORKER_INTERRUPTED', 'finished_at' => now()]);
    }
}
