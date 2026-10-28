<?php

namespace App\Services\Documents;

use App\Jobs\Documents\ProcessDocument;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\ProcessingUsage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ocr\TextQuality;
use App\Services\PlanLimitsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DocumentProcessingService
{
    public function request(User $user, Document $document, string $kind): ProcessingRun
    {
        abort_unless(in_array($kind, ['ocr', 'ai'], true), 404);
        if (! config($kind.'.enabled')) {
            throw ValidationException::withMessages(['processing' => __('processing.disabled')]);
        }
        [$run, $created] = DB::transaction(function () use ($user, $document, $kind) {
            $workspace = Workspace::lockForUpdate()->findOrFail($document->workspace_id);
            $record = Document::lockForUpdate()->findOrFail($document->id);
            Gate::forUser($user)->authorize('update', $record);
            $existing = ProcessingRun::find($record->{$kind.'_run_id'});
            $nowReadable = $kind === 'ai' && $existing && ($existing->result['skipped_ai'] ?? false)
                && app(TextQuality::class)->good($record->ocr_text ?? '');
            if ($existing && (in_array($existing->status, ['queued', 'running'], true) || ($existing->status === 'succeeded' && ! $nowReadable) || ($kind === 'ocr' && $record->ocr_status === 'ready'))) {
                return [$existing, false];
            }
            if ($kind === 'ai' && ($record->ocr_status !== 'ready' || ! trim($record->ocr_text ?? ''))) {
                throw ValidationException::withMessages(['processing' => __('processing.need_ocr')]);
            }
            $period = now('UTC')->startOfMonth()->toDateString();
            $units = $kind === 'ocr' ? $record->page_count : 1;
            app(PlanLimitsService::class)->reserveProcessing($workspace, $kind, $units, $period);
            $run = new ProcessingRun;
            $run->forceFill(['workspace_id' => $workspace->id, 'document_id' => $record->id, 'requested_by' => $user->id,
                'provider' => config('ai.provider'), 'kind' => $kind, 'status' => 'queued', 'period' => $period, 'units' => $units,
                'base_version' => $record->metadata_version, 'locale' => app()->getLocale()])->save();
            $record->forceFill([$kind.'_run_id' => $run->id, $kind.'_status' => 'queued'])->save();

            return [$run, true];
        }, 3);
        if ($created) {
            try {
                ProcessDocument::dispatch($run->id, $run->workspace_id, $kind);
            } catch (\Throwable) {
                $this->fail($run->id, $run->workspace_id, 'QUEUE_UNAVAILABLE');
                throw ValidationException::withMessages(['processing' => __('processing.failed')]);
            }
        }

        return $run;
    }

    public function claim(string $id, int $workspaceId, int $attempt): ?array
    {
        return DB::transaction(function () use ($id, $workspaceId, $attempt) {
            Workspace::lockForUpdate()->findOrFail($workspaceId);
            $run = ProcessingRun::where('workspace_id', $workspaceId)->lockForUpdate()->find($id);
            if (! $run || ! in_array($run->status, ['queued', 'running'], true) || ($run->status === 'running' && $attempt < 2)) {
                return null;
            }
            $document = Document::find($run->document_id);
            $user = User::find($run->requested_by);
            if (! $document || ! $user || ! $user->hasVerifiedEmail() || ! Gate::forUser($user)->allows('update', $document)) {
                $this->settle($run, false, 'ACCESS_REVOKED');

                return null;
            }
            $run->forceFill(['status' => 'running', 'attempts' => $attempt, 'started_at' => now()])->save();
            $document->forceFill([$run->kind.'_status' => 'running'])->save();

            return [$run, $document];
        }, 3);
    }

    private function settle(ProcessingRun $run, bool $success, ?string $error = null, bool $billable = true, bool $chargeAttempt = false): void
    {
        if (! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }
        $usage = ProcessingUsage::where('workspace_id', $run->workspace_id)->where('period', $run->period)->lockForUpdate()->firstOrFail();
        $kind = $run->kind;
        $usage->forceFill([$kind.'_reserved' => max(0, $usage->{$kind.'_reserved'} - $run->units), $kind.'_used' => $usage->{$kind.'_used'} + (($success && $billable) || $chargeAttempt ? $run->units : 0)])->save();
        $run->forceFill(['status' => $success ? 'succeeded' : 'failed', 'error_code' => $error, 'completed_at' => now(),
            'duration_ms' => $run->started_at ? max(0, (int) abs(now()->diffInMilliseconds($run->started_at))) : 0])->save();
        Document::withTrashed()->whereKey($run->document_id)->where($kind.'_run_id', $run->id)->update([$kind.'_status' => $success ? 'ready' : 'failed']);
    }

    public function complete(string $id, int $workspaceId, array $result): void
    {
        $next = DB::transaction(function () use ($id, $workspaceId, $result) {
            Workspace::lockForUpdate()->findOrFail($workspaceId);
            $run = ProcessingRun::where('workspace_id', $workspaceId)->lockForUpdate()->find($id);
            if (! $run || ! in_array($run->status, ['queued', 'running'], true)) {
                return;
            }
            $document = Document::lockForUpdate()->find($run->document_id);
            if (! $document) {
                $this->settle($run, false, 'DOCUMENT_UNAVAILABLE');

                return;
            }
            if ($run->kind === 'ocr') {
                $document->forceFill(['ocr_text' => $result['text'], 'ocr_engine' => $result['engine']])->save();
                $run->forceFill(['result' => ['engine' => $result['engine'], 'characters' => mb_strlen($result['text'])]])->save();
            } else {
                $run->forceFill(['result' => $result])->save();
            }
            $this->settle($run, true, billable: ! ($result['skipped_ai'] ?? false));
            if ($run->kind === 'ai' && ($result['review_required'] ?? false)) {
                $document->forceFill(['ai_status' => 'needs_review'])->save();
            }
            if ($run->kind === 'ocr' && ! trim($result['text'])) {
                $document->forceFill(['ocr_status' => 'empty'])->save();
            }

            return $run->kind === 'ocr' && $document->ai_requested && trim($result['text']) ? [$document, $run->requested_by, $run->locale] : null;
        }, 3);
        if ($next) {
            [$document, $userId, $locale] = $next;
            $oldLocale = app()->getLocale();
            app()->setLocale($locale);
            try {
                $user = User::find($userId);
                if ($user) {
                    $this->request($user, $document, 'ai');
                }
            } catch (ValidationException $exception) {
                $document->forceFill(['ai_status' => $exception instanceof ProcessingQuotaExceeded ? 'quota_exceeded' : 'failed'])->save();
            } finally {
                app()->setLocale($oldLocale);
            }
        }
    }

    public function fail(string $id, int $workspaceId, string $code): void
    {
        DB::transaction(function () use ($id, $workspaceId, $code) {
            if (! Workspace::lockForUpdate()->find($workspaceId)) {
                return;
            }
            $run = ProcessingRun::where('workspace_id', $workspaceId)->lockForUpdate()->find($id);
            if ($run) {
                $this->settle($run, false, $code);
            }
        }, 3);
    }

    public function cancel(Document $document): void
    {
        foreach (ProcessingRun::where('document_id', $document->id)->whereIn('status', ['queued', 'running'])->get() as $run) {
            $this->settle($run, false, 'DOCUMENT_DELETED', chargeAttempt: $run->kind === 'ai' && $run->status === 'running');
        }
    }
}
