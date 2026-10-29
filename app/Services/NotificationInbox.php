<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\DocumentReminder;
use App\Notifications\WorkspaceAlert;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class NotificationInbox
{
    public function query(User $user, Workspace $workspace)
    {
        Gate::forUser($user)->authorize('view', $workspace);
        $documents = Document::where('workspace_id', $workspace->id)->where('status', 'stored')->select('id');

        $invitations = WorkspaceInvitation::where(fn ($q) => $q->where('email', $user->email)->orWhere(fn ($q) => $q->where('invited_by', $user->id)->where('workspace_id', $workspace->id)))->select('id');

        return $user->notifications()->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($documents, $invitations) {
                $query->where(fn ($q) => $q->where('type', WorkspaceAlert::class)->where('data->event', 'storage_near_limit'))
                    ->orWhere(fn ($q) => $q->whereIn('type', [DocumentReminder::class, WorkspaceAlert::class])->whereIn('data->document_id', $documents))
                    ->orWhere(fn ($q) => $q->where('type', WorkspaceAlert::class)->whereIn('data->event', ['invitation_received', 'invitation_accepted'])->whereIn('data->invitation_id', $invitations));
            });
    }

    public function find(User $user, Workspace $workspace, string $id): DatabaseNotification
    {
        return $this->query($user, $workspace)->whereKey($id)->firstOrFail();
    }

    public function items($notifications, User $user, string $locale): Collection
    {
        $invitations = WorkspaceInvitation::with(['workspace', 'acceptedBy'])->whereIn('id', $notifications->pluck('data.invitation_id')->filter())->get()->keyBy('id');
        $documents = Document::with('reminder')->whereIn('id', $notifications->pluck('data.document_id')->filter())->get()->keyBy('id');

        return collect($notifications->all())->map(function ($notification) use ($documents, $invitations, $user, $locale) {
            $data = $notification->data;
            $document = $documents->get($data['document_id'] ?? '');
            if ($document && ! Gate::forUser($user)->allows('view', $document)) {
                return null;
            }
            $event = $notification->type === DocumentReminder::class ? 'reminder' : ($data['event'] ?? '');
            if (in_array($event, ['invitation_received', 'invitation_accepted'], true)) {
                $invitation = $invitations->get($data['invitation_id'] ?? '');
                if (! $invitation || ($event === 'invitation_received' ? $invitation->email !== $user->email : ($invitation->invited_by !== $user->id || ! Gate::forUser($user)->allows('view', $invitation->workspace)))) {
                    return null;
                }

                return ['id' => $notification->id, 'event' => $event, 'title' => __('team.notice_'.$event), 'document_title' => $invitation->workspace->name,
                    'text' => $event === 'invitation_received' ? __('team.notice_received_text') : __('team.notice_accepted_text', ['name' => $invitation->acceptedBy?->name ?? '']),
                    'read' => $notification->read_at !== null, 'created' => $notification->created_at->timezone(config('vault.display_timezone'))->format('d.m.Y H:i'),
                    'actionable' => false, 'resolved' => false, 'snooze_max' => null,
                    'url' => route('translated_'.$locale.'.invitations.show', ['invitation' => $invitation->id])];
            }
            if (! in_array($event, ['reminder', 'processing_failed', 'storage_near_limit'], true)) {
                return null;
            }
            $reminder = $event === 'reminder' ? $document?->reminder : null;
            $actionable = $reminder && $reminder->id === ($data['reminder_id'] ?? null) && $reminder->status === 'active' && $reminder->cycle === ($data['cycle'] ?? null) && Gate::forUser($user)->allows('update', $reminder);

            return ['id' => $notification->id, 'event' => $event, 'title' => __('notifications.event_'.$event), 'document_title' => $document?->title,
                'text' => __('notifications.text_'.$event, ['date' => isset($data['due_on']) ? CarbonImmutable::parse($data['due_on'])->format('d.m.Y') : '']),
                'read' => $notification->read_at !== null, 'created' => $notification->created_at->timezone(config('vault.display_timezone'))->format('d.m.Y H:i'),
                'actionable' => $actionable, 'resolved' => $reminder && $reminder->status !== 'active',
                'snooze_max' => $reminder?->due_on->copy()->subDay()->toDateString(),
                'url' => $document ? route('translated_'.$locale.'.documents.show', ['document' => $document->id]).($event === 'reminder' ? '#reminders' : '') : route('translated_'.$locale.'.documents.index')];
        })->filter()->values();
    }

    public function mark(User $user, Workspace $workspace, string $id): void
    {
        $this->find($user, $workspace, $id)->markAsRead();
    }

    public function changeReminder(User $user, Workspace $workspace, string $id, string $action, ?string $date): void
    {
        $notification = $this->find($user, $workspace, $id);
        abort_unless($notification->type === DocumentReminder::class && is_int($notification->data['cycle'] ?? null), 409);
        $reminder = Reminder::where('workspace_id', $workspace->id)->findOrFail($notification->data['reminder_id'] ?? '');
        app(ReminderService::class)->change($user, $reminder, $action, $date, $notification->data['cycle']);
        $notification->markAsRead();
    }
}
