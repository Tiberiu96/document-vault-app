<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Ramsey\Uuid\Uuid;

class WorkspaceAlert extends Notification
{
    public function __construct(public int $workspaceId, public string $event, public array $identifiers, string $deduplicationKey)
    {
        $this->id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'vaulty:inbox:'.$deduplicationKey)->toString();
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['event' => $this->event, ...$this->identifiers];
    }
}
