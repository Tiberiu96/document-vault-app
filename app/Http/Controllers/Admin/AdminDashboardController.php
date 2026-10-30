<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\OperationalSummary;
use App\Services\Backup\BackupRestoreControl;
use App\Services\Backup\BackupState;
use App\Services\Documents\DocumentProcessingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdminDashboardController extends Controller
{
    public function index(Request $request, OperationalSummary $operations)
    {
        $filters = $request->validate(['failed_kind' => ['nullable', 'in:ocr,ai'], 'failed_workspace' => ['nullable', 'integer', 'min:1'], 'audit_actor' => ['nullable', 'integer', 'min:1']]);
        $runs = ProcessingRun::where('status', 'failed')
            ->when($filters['failed_kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['failed_workspace'] ?? null, fn ($q, $id) => $q->where('workspace_id', $id))
            ->latest()->orderByDesc('id')->paginate(15, ['id', 'document_id', 'workspace_id', 'kind', 'attempts', 'duration_ms', 'error_code', 'created_at'], 'failed_page')->withQueryString();
        $actor = Auth::guard('admin')->user();
        foreach ($runs as $run) {
            $doc = Document::find($run->document_id, ['id', 'workspace_id', 'uploaded_by', 'status']);
            $run->setAttribute('can_retry', $actor->hasVerifiedEmail() && $doc && $doc->status === 'stored' && Gate::forUser($actor)->allows('update', $doc) && OperationalSummary::retryable($run->error_code));
        }

        return view('admin.dashboard', [
            'stats' => ['Utilizatori' => User::count(), 'Spatii de lucru' => Workspace::count(), 'Documente confirmate' => Document::where('status', 'stored')->count(), 'Stocare utilizata (MB)' => number_format(Workspace::sum('storage_used_bytes') / 1048576, 2), 'Remindere active' => Reminder::where('status', 'active')->count(), 'Joburi esuate' => DB::table('failed_jobs')->count()],
            'queues' => $operations->queues(), 'runs' => $runs,
            'events' => DB::table('admin_audit_events')->when($filters['audit_actor'] ?? null, fn ($q, $id) => $q->where('actor_id', $id))->latest('id')->paginate(15, ['id', 'actor_id', 'action', 'target_type', 'target_id', 'created_at'], 'audit_page')->withQueryString(), 'filters' => $filters,
        ]);
    }

    public function backup()
    {
        $allowed = ['status', 'phase', 'started_at', 'finished_at', 'last_success_at', 'duration_seconds', 'error_code', 'alert_reason', 'alert_at', 'monitor_error', 'bytes_processed', 'bytes_added'];

        return view('admin.backup', ['enabled' => app()->environment('production') && config('backup.enabled'), 'state' => array_intersect_key(app(BackupState::class)->read(), array_flip($allowed)), 'inspection' => app(BackupRestoreControl::class)->read('inspection'), 'restoreState' => app(BackupRestoreControl::class)->read('status'), 'restoreEnabled' => config('backup.restore_enabled') && config('backup.restore_dedicated_redis')]);
    }

    public function notifications(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', 'in:pending,processing,sent,cancelled,skipped,uncertain'], 'workspace_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])]]);
        $deliveries = ReminderDelivery::query()->join('document_reminders', 'document_reminders.id', '=', 'reminder_deliveries.reminder_id')
            ->select(['reminder_deliveries.id', 'document_reminders.workspace_id', 'document_reminders.user_id', 'reminder_deliveries.status', 'reminder_deliveries.scheduled_for', 'reminder_deliveries.in_app_at', 'reminder_deliveries.email_sent_at', 'reminder_deliveries.attempts'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('reminder_deliveries.status', $status))
            ->when($filters['workspace_id'] ?? null, fn ($q, $id) => $q->where('document_reminders.workspace_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->where('reminder_deliveries.scheduled_for', '>=', CarbonImmutable::parse($date, config('vault.display_timezone'))->utc()))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->where('reminder_deliveries.scheduled_for', '<', CarbonImmutable::parse($date, config('vault.display_timezone'))->addDay()->utc()))
            ->orderByDesc('reminder_deliveries.scheduled_for')->orderByDesc('reminder_deliveries.id')->paginate(25)->withQueryString();

        return view('admin.notifications', ['deliveries' => $deliveries, 'filters' => $filters]);
    }

    public function users(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'verified' => ['nullable', 'in:yes,no'], 'role' => ['nullable', 'in:client,admin']]);
        $q = $filters['q'] ?? '';
        $users = User::query()->select(['id', 'name', 'email', 'email_verified_at', 'is_super_admin', 'admin_mfa_confirmed_at', 'created_at']);
        if (! empty($filters['verified'])) {
            $filters['verified'] === 'yes' ? $users->whereNotNull('email_verified_at') : $users->whereNull('email_verified_at');
        }
        if (! empty($filters['role'])) {
            $users->where('is_super_admin', $filters['role'] === 'admin');
        }
        if ($q !== '') {
            $term = '%'.addcslashes($q, '%_\\').'%';
            $users->where(fn ($query) => $query->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        return view('admin.users', ['users' => $users->latest()->orderByDesc('id')->paginate(25)->withQueryString(), 'q' => $q, 'filters' => $filters]);
    }

    public function workspaces(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'type' => ['nullable', 'in:personal,business'], 'plan' => ['nullable', 'in:free,pro'], 'upgrade' => ['nullable', 'in:pending']]);
        $query = Workspace::with('owner:id,name,email')->withCount('members')
            ->selectSub(DB::table('workspace_upgrade_requests')->selectRaw('1')->whereColumn('workspace_id', 'workspaces.id')->where('status', 'pending')->limit(1), 'pending_upgrade');
        if (($filters['upgrade'] ?? null) === 'pending') {
            $query->whereExists(fn ($query) => $query->selectRaw('1')->from('workspace_upgrade_requests')->whereColumn('workspace_id', 'workspaces.id')->where('status', 'pending'));
        }
        if (! empty($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhereHas('owner', fn ($owner) => $owner->where('name', 'like', $term)->orWhere('email', 'like', $term)));
        }
        $query->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))->when($filters['plan'] ?? null, fn ($q, $plan) => $q->where('plan_code', $plan));

        return view('admin.workspaces', ['workspaces' => $query->latest()->orderByDesc('id')->paginate(25)->withQueryString(), 'filters' => $filters]);
    }

    public function retry(Request $request, string $run, DocumentProcessingService $processing)
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        DB::transaction(function () use ($run, $processing) {
            $candidate = ProcessingRun::findOrFail($run);
            Workspace::lockForUpdate()->findOrFail($candidate->workspace_id);
            $document = Document::lockForUpdate()->findOrFail($candidate->document_id);
            $record = ProcessingRun::lockForUpdate()->findOrFail($run);
            Gate::authorize('update', $document);
            abort_unless(Auth::guard('admin')->user()->hasVerifiedEmail() && $document->status === 'stored', 403);
            if ($record->status !== 'failed' || $document->{$record->kind.'_run_id'} !== $record->id || ! OperationalSummary::retryable($record->error_code)) {
                throw ValidationException::withMessages(['retry' => 'Aceasta procesare nu poate fi reluata in starea curenta.']);
            }
            $processing->request(Auth::guard('admin')->user(), $document, $record->kind);
            AdminAudit::record(Auth::guard('admin')->user(), 'processing.retry_requested', 'processing_run', $record->id);
        }, 3);

        return back()->with('admin_status', 'Reprocesarea a fost solicitata cu permisiunile si cotele workspace-ului.');
    }
}
