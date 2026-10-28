<?php

namespace App\Services;

use App\Jobs\DeliverReminder;
use App\Models\Document;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReminderService
{
    public function syncDocument(Document $document, User $actor, string $locale): ?Reminder
    {
        return DB::transaction(function () use ($document, $actor, $locale) {
            Workspace::lockForUpdate()->findOrFail($document->workspace_id);
            $doc = Document::lockForUpdate()->findOrFail($document->id);
            $reminder = Reminder::where('document_id', $doc->id)->lockForUpdate()->first();
            if ($doc->status !== 'stored') {
                return null;
            }
            $date = $doc->expires_on?->toDateString();
            if (! $date || $date <= now(config('reminders.timezone'))->toDateString()) {
                if ($reminder) {
                    $this->cancelPending($reminder);
                    if ($reminder->status === 'active') {
                        $reminder->forceFill(['status' => 'disabled'])->save();
                    }
                }

                return $reminder;
            }
            if ($reminder && $reminder->due_on->toDateString() === $date) {
                return $reminder;
            }
            if (! $reminder) {
                $reminder = new Reminder;
                $reminder->forceFill(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id, 'user_id' => $actor->id, 'cycle' => 0]);
            }
            $this->cancelPending($reminder);
            $reminder->forceFill(['due_on' => $date, 'locale' => array_key_exists($locale, config('vault.locales')) ? $locale : 'ro', 'cycle' => $reminder->cycle + 1, 'status' => 'active', 'completed_at' => null, 'snoozed_until' => null])->save();
            $this->plan($reminder);

            return $reminder;
        }, 3);
    }

    private function plan(Reminder $reminder, ?CarbonImmutable $snooze = null): void
    {
        $times = [];
        foreach (config('reminders.days_before') as $days) {
            $time = CarbonImmutable::parse($reminder->due_on->toDateString(), config('reminders.timezone'))->subDays($days)->setTime(config('reminders.hour'), 0);
            if ($time->isFuture() && (! $snooze || $time->greaterThanOrEqualTo($snooze))) {
                $times[$days] = $time;
            }
        }
        if ($snooze && ! collect($times)->contains(fn ($time) => $time->equalTo($snooze))) {
            $times[0] = $snooze;
        }
        foreach ($times as $days => $time) {
            $delivery = new ReminderDelivery;
            $delivery->forceFill(['reminder_id' => $reminder->id, 'cycle' => $reminder->cycle, 'days_before' => $days, 'scheduled_for' => $time->utc(), 'status' => 'pending'])->save();
        }
    }

    public function change(User $user, Reminder $reminder, string $action, ?string $date = null, ?int $cycle = null): void
    {
        DB::transaction(function () use ($user, $reminder, $action, $date, $cycle) {
            Workspace::lockForUpdate()->findOrFail($reminder->workspace_id);
            $doc = Document::lockForUpdate()->findOrFail($reminder->document_id);
            $record = Reminder::lockForUpdate()->findOrFail($reminder->id);
            Gate::forUser($user)->authorize('update', $record);
            abort_unless($doc->status === 'stored', 409);
            if ($cycle !== null && $record->cycle !== $cycle) {
                throw ValidationException::withMessages(['reminder' => __('reminders.stale')]);
            }
            if ($action === 'complete' || $action === 'disable') {
                $this->cancelPending($record);
                $record->forceFill(['status' => $action === 'complete' ? 'completed' : 'disabled', 'completed_at' => $action === 'complete' ? now() : null])->save();

                return;
            }
            $day = CarbonImmutable::parse($date, config('reminders.timezone'))->setTime(config('reminders.hour'), 0);
            if (! $day->isFuture() || $day->toDateString() <= now(config('reminders.timezone'))->toDateString()) {
                throw ValidationException::withMessages(['date' => __('reminders.future_date')]);
            }
            if ($action === 'renew') {
                $doc->forceFill(['expires_on' => $day->toDateString(), 'metadata_version' => $doc->metadata_version + 1])->save();
                // Reinnoirea explicita poate reactiva inclusiv aceeasi data.
                $this->cancelPending($record);
                $record->forceFill(['due_on' => $day->toDateString(), 'status' => 'active', 'completed_at' => null, 'snoozed_until' => null, 'cycle' => $record->cycle + 1, 'locale' => app()->getLocale()])->save();
                $this->plan($record);
            } elseif ($action === 'snooze') {
                if ($record->status !== 'active' || $day->toDateString() >= $record->due_on->toDateString()) {
                    throw ValidationException::withMessages(['date' => __('reminders.snooze_before')]);
                }
                $this->cancelPending($record);
                $record->forceFill(['cycle' => $record->cycle + 1, 'snoozed_until' => $day->utc()])->save();
                $this->plan($record, $day);
            }
        }, 3);
    }

    public function cancelPending(Reminder $reminder): void
    {
        if ($reminder->exists) {
            $reminder->deliveries()->whereIn('status', ['pending', 'processing'])->update(['status' => 'cancelled', 'finished_at' => now()]);
        }
    }

    public function deleteNotifications(Document $document): void
    {
        $ids = ReminderDelivery::whereHas('reminder', fn ($q) => $q->where('document_id', $document->id))->pluck('id');
        DB::table('notifications')->where(function ($q) use ($ids, $document) {
            $q->whereIn('id', $ids)->orWhere(fn ($q) => $q->where('workspace_id', $document->workspace_id)->where('data->document_id', $document->id));
        })->delete();
    }

    public function dispatchDue(): int
    {
        if (! config('reminders.enabled')) {
            return 0;
        }
        $count = 0;
        ReminderDelivery::where('status', 'pending')->where('scheduled_for', '<=', now())->chunkById(100, function ($deliveries) use (&$count) {
            foreach ($deliveries as $delivery) {
                DeliverReminder::dispatch($delivery->id);
                $count++;
            }
        });
        // Un worker oprit in timpul SMTP nu justifica retransmiterea oarba.
        ReminderDelivery::where('status', 'processing')->where('claimed_at', '<', now()->subMinutes(2))->update(['status' => 'uncertain', 'failure_code' => 'WORKER_INTERRUPTED', 'finished_at' => now()]);

        return $count;
    }
}
