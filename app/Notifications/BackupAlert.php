<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackupAlert extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $reason)
    {
        $this->onQueue('high')->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Vaulty: verificare backup necesara')->line(match ($this->reason) {
            'slow' => 'Backupul ruleaza de peste 15 minute.','missing' => 'Backupul programat nu a pornit.','stale' => 'Nu exista un backup reusit recent.',default => 'Backupul necesita verificare. Consultati panoul de administrare.'
        });
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }
}
