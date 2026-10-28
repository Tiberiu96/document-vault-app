<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Document;
use App\Models\Tag;
use App\Models\Workspace;
use App\Services\Documents\DocumentProcessingService;
use App\Services\WebsiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DocumentProcessingController extends Controller
{
    private function document(Request $request, string $id): Document
    {
        $document = Document::where('workspace_id', $request->attributes->get('workspace')->id)->with(['aiRun', 'ocrRun', 'tags'])->findOrFail($id);
        Gate::authorize('view', $document);

        return $document;
    }

    public function start(Request $request, string $document, string $kind, DocumentProcessingService $processing)
    {
        $record = $this->document($request, $document);
        Gate::authorize('update', $record);
        $run = $processing->request($request->user(), $record, $kind);

        return redirect()->route('documents.show', ['document' => $record->id])->with('library_status', __($run->status === 'succeeded' ? 'processing.already_analyzed' : 'processing.queued_notice'));
    }

    public function status(Request $request, string $document)
    {
        $record = $this->document($request, $document);

        $pendingRun = in_array($record->ocr_status, ['queued', 'running'], true) ? $record->ocrRun : $record->aiRun;
        $readyForReview = $record->ai_status === 'ready' && $record->aiRun && ! ($record->aiRun->result['review_required'] ?? true)
            && $record->metadata_version === $record->aiRun->base_version && Gate::allows('update', $record);

        return response()->json([
            'pending' => in_array($record->ocr_status, ['queued', 'running'], true) || in_array($record->ai_status, ['queued', 'running'], true) || ($record->ai_requested && $record->ocr_status === 'ready' && $record->ai_status === 'idle'),
            'source_released' => (bool) $record->source_released_at,
            'ocr_status' => $record->ocr_status, 'ai_status' => $record->ai_status,
            'ocr_label' => __('processing.states.'.$record->ocr_status), 'ai_label' => __('processing.states.'.$record->ai_status),
            'elapsed_seconds' => $pendingRun ? max(0, (int) abs(now()->diffInSeconds($pendingRun->created_at))) : 0,
            'ready_for_review' => (bool) $readyForReview,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function suggestions(Request $request, string $document)
    {
        $record = $this->document($request, $document);
        Gate::authorize('update', $record);
        $run = $record->aiRun;
        if (! $run || $record->ai_status !== 'ready' || ($run->result['review_required'] ?? true)) {
            return redirect()->route('documents.show', ['document' => $record->id])->with('library_status', __('processing.manual_review'));
        }
        if ($record->metadata_version !== $run->base_version) {
            return redirect()->route('documents.show', ['document' => $record->id])->withErrors(['processing' => __('processing.stale')]);
        }
        $prefill = clone $record;
        $fields = $run->result['fields'];
        foreach (['summary', 'supplier', 'issued_on', 'expires_on'] as $field) {
            if ($fields[$field] !== null) {
                $prefill->setAttribute($field, $fields[$field]);
            }
        }
        if ($fields['category_key']) {
            $prefill->category_id = Category::forWorkspace($record->workspace)->where('system_key', $fields['category_key'])->value('id');
        }
        if ($fields['amount'] !== null && $fields['currency']) {
            [$whole, $fraction] = array_pad(explode('.', $fields['amount'], 2), 2, '');
            $prefill->amount_minor = ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
            $prefill->currency = $fields['currency'];
        }
        $suggestedTags = Tag::whereNull('workspace_id')->whereIn('system_key', $fields['tag_keys'])->get();
        $prefill->setRelation('tags', $record->tags->merge($suggestedTags)->unique('id'));
        $workspace = $record->workspace;

        return view('documents.form', [
            ...app(WebsiteContext::class)->forPage($request, 'documents'), 'section' => 'documents', 'document' => $prefill,
            'workspace' => $workspace, 'workspaces' => $request->user()->workspaces()->get()->filter(fn ($w) => Gate::allows('view', $w)),
            'categories' => Category::forWorkspace($workspace)->get(), 'tags' => Tag::forWorkspace($workspace)->get(),
            'suggestionWarnings' => $run->result['warnings'] ?? [], 'suggestionRunId' => $run->id, 'suggestionPartial' => $run->result['partial'] ?? false,
        ]);
    }

    public function reject(Request $request, string $document)
    {
        DB::transaction(function () use ($request, $document) {
            Workspace::lockForUpdate()->findOrFail($request->attributes->get('workspace')->id);
            $record = $this->document($request, $document);
            Gate::authorize('update', $record);
            abort_unless(in_array($record->ai_status, ['ready', 'needs_review'], true), 409);
            $record->forceFill(['ai_status' => 'rejected'])->save();
        });

        return redirect()->route('documents.show', ['document' => $document])->with('library_status', __('processing.rejected_notice'));
    }
}
