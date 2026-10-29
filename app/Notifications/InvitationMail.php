<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

class InvitationMail extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public string $invitationId, public string $kind, #[\SensitiveParameter] public ?string $token = null)
    {
        $this->onQueue('high')->afterCommit()->locale(app()->getLocale());
    }

    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $invitation = WorkspaceInvitation::find($this->invitationId);
        if (! $invitation || ! $invitation->inviter || ! Gate::forUser($invitation->inviter)->allows('manageMembers', $invitation->workspace)) {
            return false;
        }
        if ($this->kind === 'received' && $invitation->role === 'admin' && $invitation->workspace->owner_id !== $invitation->invited_by) {
            return false;
        }

        return $this->kind === 'received'
            ? $invitation->pending() && hash_equals($invitation->token_hash, hash('sha256', (string) $this->token))
            : ($this->kind === 'accepted' && $invitation->accepted_at !== null && $notifiable instanceof User && $notifiable->id === $invitation->invited_by);
    }

    public function actionUrl(): string
    {
        $name = $this->kind === 'received' ? 'invitations.link' : 'invitations.show';
        $params = ['invitation' => $this->invitationId];
        if ($this->kind === 'received') {
            $params['token'] = $this->token;
        }

        return rtrim(config('app.url'), '/').route('translated_'.($this->locale ?? 'ro').'.'.$name, $params, false);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = WorkspaceInvitation::with(['workspace', 'inviter', 'acceptedBy'])->findOrFail($this->invitationId);

        return (new MailMessage)->subject(__('team.mail_'.$this->kind.'_subject', ['workspace' => $invitation->workspace->name]))
            ->view('mail.invitation', ['invitation' => $invitation, 'kind' => $this->kind, 'actionUrl' => $this->actionUrl(), 'locale' => $this->locale ?? 'ro']);
    }
}
