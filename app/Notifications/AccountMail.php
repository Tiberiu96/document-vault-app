<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

class AccountMail extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $deleteWhenMissingModels = true;

    public bool $failOnTimeout = true;

    public function __construct(public string $kind, #[\SensitiveParameter] public ?string $token = null)
    {
        if (! in_array($kind, ['verify', 'reset', 'changed'], true)) {
            throw new \InvalidArgumentException('Unsupported account notification.');
        }
        $this->onQueue('high')->afterCommit()->locale(app()->getLocale());
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return match ($this->kind) {
            'verify' => ! $notifiable->hasVerifiedEmail(),
            'reset' => Password::broker()->tokenExists($notifiable, (string) $this->token),
            default => true,
        };
    }

    public function actionUrl(object $notifiable): string
    {
        $locale = $this->locale ?? config('app.locale');
        $route = fn (string $name, array $params = []) => route('translated_'.$locale.'.'.$name, $params, false);
        $path = match ($this->kind) {
            'verify' => URL::temporarySignedRoute('translated_'.$locale.'.verification.verify', now()->addMinutes(60), [
                'id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification()),
            ], absolute: false),
            'reset' => $route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]),
            default => $route('password.request'),
        };

        // Domeniul vine numai din configuratia de incredere, niciodata din Host primit.
        return rtrim(config('app.url'), '/').$path;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = [
            'kind' => $this->kind, 'name' => $notifiable->name, 'actionUrl' => $this->actionUrl($notifiable),
            'brand' => config('app.name'), 'locale' => $this->locale ?? app()->getLocale(),
            'minutes' => $this->kind === 'reset' ? config('auth.passwords.users.expire') : 60,
        ];

        return (new MailMessage)->subject(__('account_mail.'.$this->kind.'_subject', ['brand' => config('app.name')]))
            ->view(['html' => 'mail.account', 'text' => 'mail.account-text'], $data);
    }
}
