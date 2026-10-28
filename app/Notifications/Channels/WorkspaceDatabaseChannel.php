<?php

namespace App\Notifications\Channels;

use App\Notifications\DocumentReminder;
use App\Notifications\WorkspaceAlert;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

class WorkspaceDatabaseChannel extends DatabaseChannel
{
    public function send($notifiable, Notification $notification)
    {
        if (! $notification instanceof DocumentReminder && ! $notification instanceof WorkspaceAlert) {
            return parent::send($notifiable, $notification);
        }
        $payload = $this->buildPayload($notifiable, $notification);
        $payload['workspace_id'] = $notification instanceof DocumentReminder ? $notification->reminder->workspace_id : $notification->workspaceId;
        unset($payload['id']);

        return $notifiable->routeNotificationFor('database', $notification)->firstOrCreate(['id' => $notification->id], $payload);
    }
}
