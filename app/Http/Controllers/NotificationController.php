<?php

namespace App\Http\Controllers;

use App\Services\NotificationInbox;
use App\Services\NotificationPreferences;
use App\Services\WebsiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationInbox $inbox)
    {
        $workspace = $request->attributes->get('workspace');
        $filter = $request->validate(['filter' => ['nullable', 'in:all,unread']])['filter'] ?? 'all';
        $query = $inbox->query($request->user(), $workspace);
        $unread = (clone $query)->whereNull('read_at')->count();
        if ($filter === 'unread') {
            $query->whereNull('read_at');
        }
        $notifications = $query->latest()->paginate(15)->withQueryString();

        return view('notifications.index', [...app(WebsiteContext::class)->forPage($request, 'notifications'), 'section' => 'notifications', 'workspace' => $workspace,
            'workspaces' => $request->user()->workspaces()->get()->filter(fn ($w) => Gate::allows('view', $w)), 'unread' => $unread, 'filter' => $filter,
            'notifications' => $notifications, 'items' => $inbox->items($notifications->getCollection(), $request->user(), app()->getLocale()), 'preferences' => app(NotificationPreferences::class)->forUser($request->user())]);
    }

    public function update(Request $request, string $notification, NotificationInbox $inbox)
    {
        $data = $request->validate(['action' => ['required', 'in:read,open,complete,disable,snooze'], 'date' => ['required_if:action,snooze', 'nullable', 'date_format:Y-m-d', 'after:'.now(config('reminders.timezone'))->toDateString()]]);
        $user = $request->user();
        $workspace = $request->attributes->get('workspace');
        if (in_array($data['action'], ['read', 'open'], true)) {
            $record = $inbox->find($user, $workspace, $notification);
            $item = $inbox->items(collect([$record]), $user, app()->getLocale())->first();
            abort_unless($item, 404);
            $record->markAsRead();
            if ($data['action'] === 'open') {
                return redirect()->to($item['url']);
            }
        } else {
            $inbox->changeReminder($user, $workspace, $notification, $data['action'], $data['date'] ?? null);
        }

        return back()->with('library_status', __('notifications.saved'));
    }

    public function readAll(Request $request, NotificationInbox $inbox)
    {
        $inbox->query($request->user(), $request->attributes->get('workspace'))->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('library_status', __('notifications.read_all_done'));
    }

    public function preferences(Request $request)
    {
        $keys = array_keys(NotificationPreferences::DEFAULTS);
        $data = $request->validate(array_fill_keys($keys, ['required', 'boolean']));
        $request->user()->forceFill(['notification_preferences' => array_map(fn ($v) => (bool) $v, $data)])->save();

        return back()->with('library_status', __('notifications.preferences_saved'));
    }
}
