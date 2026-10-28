<?php

namespace App\Notifications;

use App\Models\Reminder;
use App\Models\ReminderDelivery;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Trimiterea efectiva se face numai din DeliverReminder, pe high, dupa verificarea starii.
class DocumentReminder extends Notification
{
    public function __construct(public Reminder $reminder, public ReminderDelivery $delivery)
    {
        $this->id = $delivery->id;
        $this->locale($reminder->locale);
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['document_id' => $this->reminder->document_id, 'reminder_id' => $this->reminder->id, 'due_on' => $this->reminder->due_on->toDateString(), 'days_before' => $this->delivery->days_before, 'cycle' => $this->delivery->cycle];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $path = route('translated_'.$this->reminder->locale.'.documents.show', ['document' => $this->reminder->document_id], false);

        return (new MailMessage)->subject(__('reminders.email_subject', ['brand' => config('app.name')]))->view(['html' => 'mail.reminder', 'text' => 'mail.reminder-text'], [
            'brand' => config('app.name'), 'locale' => $this->reminder->locale, 'name' => $notifiable->name,
            'title' => $this->reminder->document->title, 'due' => $this->reminder->due_on->format('d.m.Y'),
            'actionUrl' => rtrim(config('app.url'), '/').$path,
        ]);
    }
}
