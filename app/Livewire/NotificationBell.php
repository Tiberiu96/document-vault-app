<?php

namespace App\Livewire;

use App\Models\Workspace;
use App\Services\LanguageCatalog;
use App\Services\NotificationInbox;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NotificationBell extends Component
{
    #[Locked]
    public int $workspaceId;

    #[Locked]
    public string $locale;

    public function mount(int $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        $this->locale = app()->getLocale();
    }

    private function context(): array
    {
        $user = Auth::guard('web')->user()?->fresh();
        abort_unless($user && $user->hasVerifiedEmail(), 403);
        abort_unless((int) session('active_workspace_id') === $this->workspaceId, 409);
        abort_unless(app(LanguageCatalog::class)->available()->contains('locale', $this->locale), 404);
        app()->setLocale($this->locale);
        $workspace = Workspace::findOrFail($this->workspaceId);
        Gate::forUser($user)->authorize('view', $workspace);

        return [$user, $workspace];
    }

    public function refreshInbox(): void
    {
        [$user] = $this->context();
        abort_unless(RateLimiter::attempt('notification-poll:'.$user->id, 30, fn () => true, 60), 429);
    }

    private function canWrite(): array
    {
        $context = $this->context();
        abort_if(session()->has('admin.impersonation'), 403);
        abort_unless(RateLimiter::attempt('notification-change:'.$context[0]->id, 30, fn () => true, 60), 429);

        return $context;
    }

    public function markRead(string $id): void
    {
        [$user, $workspace] = $this->canWrite();
        app(NotificationInbox::class)->mark($user, $workspace, $id);
    }

    public function openNotification(string $id): void
    {
        [$user, $workspace] = $this->canWrite();
        $inbox = app(NotificationInbox::class);
        $notification = $inbox->find($user, $workspace, $id);
        $item = $inbox->items(collect([$notification]), $user, $this->locale)->first();
        abort_unless($item, 404);
        $notification->markAsRead();
        $this->redirect($item['url']);
    }

    public function render()
    {
        [$user, $workspace] = $this->context();
        $inbox = app(NotificationInbox::class);

        return view('livewire.notification-bell', ['unread' => $inbox->query($user, $workspace)->whereNull('read_at')->count(),
            'items' => $inbox->items($inbox->query($user, $workspace)->latest()->limit(5)->get(), $user, $this->locale),
            'centerUrl' => route('translated_'.$this->locale.'.notifications.index')]);
    }
}
