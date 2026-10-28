<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReminderRequest;
use App\Models\Document;
use App\Models\Reminder;
use App\Models\Workspace;
use App\Services\ReminderService;
use App\Services\WebsiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReminderController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', 'in:active,completed,disabled,all']]);
        $workspace = $request->attributes->get('workspace');
        $query = Reminder::where('workspace_id', $workspace->id)->whereHas('document', fn ($q) => $q->where('status', 'stored'))->with(['document', 'user', 'deliveries' => fn ($q) => $q->orderBy('scheduled_for')]);
        if (($filters['status'] ?? 'active') !== 'all') {
            $query->where('status', $filters['status'] ?? 'active');
        }

        return view('reminders.index', [
            ...app(WebsiteContext::class)->forPage($request, 'reminders'), 'section' => 'reminders',
            'workspace' => $workspace, 'workspaces' => $request->user()->workspaces()->get()->filter(fn ($w) => Gate::allows('view', $w)),
            'reminders' => $query->orderBy('due_on')->paginate(15)->withQueryString(), 'filter' => $filters['status'] ?? 'active',
        ]);
    }

    public function store(ReminderRequest $request, string $document, ReminderService $service)
    {
        DB::transaction(function () use ($request, $document, $service) {
            Workspace::lockForUpdate()->findOrFail($request->attributes->get('workspace')->id);
            $record = Document::where('workspace_id', $request->attributes->get('workspace')->id)->lockForUpdate()->findOrFail($document);
            Gate::authorize('update', $record);
            abort_unless($record->status === 'stored', 409);
            if ($record->metadata_version !== (int) $request->validated('metadata_version') || Reminder::where('document_id', $record->id)->exists()) {
                throw ValidationException::withMessages(['reminder' => __('reminders.stale')]);
            }
            // Data documentului este autoritatea; activarea nu o suprascrie.
            $date = $record->expires_on?->toDateString() ?? $request->validated('date');
            if (! $date || $date <= now(config('reminders.timezone'))->toDateString()) {
                throw ValidationException::withMessages(['date' => __('reminders.future_date')]);
            }
            if (! $record->expires_on) {
                $record->forceFill(['expires_on' => $date, 'metadata_version' => $record->metadata_version + 1])->save();
            }
            $service->syncDocument($record, $request->user(), app()->getLocale());
        }, 3);

        return redirect()->route('documents.show', ['document' => $document])->withFragment('reminders')->with('library_status', __('reminders.saved'));
    }

    public function update(ReminderRequest $request, string $reminder, ReminderService $service)
    {
        $record = Reminder::where('workspace_id', $request->attributes->get('workspace')->id)->whereHas('document')->findOrFail($reminder);
        Gate::authorize('update', $record);
        $service->change($request->user(), $record, $request->validated('action'), $request->validated('date'), (int) $request->validated('cycle'));

        return redirect()->route('documents.show', ['document' => $record->document_id])->withFragment('reminders')->with('library_status', __('reminders.saved'));
    }
}
