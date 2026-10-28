<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentRequest;
use App\Http\Resources\DraftUploadResource;
use App\Jobs\Documents\PurgeDocument;
use App\Models\Category;
use App\Models\Document;
use App\Models\Tag;
use App\Models\Workspace;
use App\Services\Documents\DocumentLibrary;
use App\Services\Documents\DocumentProcessingService;
use App\Services\Documents\DraftSource;
use App\Services\Documents\ProcessingQuotaExceeded;
use App\Services\WebsiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DocumentController extends Controller
{
    private function context(Request $request, string $section): array
    {
        $workspace = $request->attributes->get('workspace');

        return [
            ...app(WebsiteContext::class)->forPage($request, 'documents'), 'section' => $section,
            'workspace' => $workspace, 'workspaces' => $request->user()->workspaces()->get()->filter(fn ($w) => Gate::allows('view', $w)),
            'categories' => Category::forWorkspace($workspace)->get(), 'tags' => Tag::forWorkspace($workspace)->orderBy('name')->get(),
        ];
    }

    private function document(Request $request, string $id): Document
    {
        $query = Document::where('workspace_id', $request->attributes->get('workspace')->id)->with(['category', 'tags', 'reminder.user', 'reminder.deliveries' => fn ($q) => $q->orderBy('scheduled_for')]);
        $document = $query->findOrFail($id);
        Gate::authorize('view', $document);

        return $document;
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'integer'], 'tag' => ['nullable', 'integer'], 'sort' => ['nullable', 'in:newest,oldest,title']]);
        $query = Document::where('workspace_id', $request->attributes->get('workspace')->id)->where('status', 'stored')->with(['category', 'tags']);
        if (! empty($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('original_name', 'like', $term)->orWhere('supplier', 'like', $term));
        }
        $query->when($filters['category'] ?? null, fn ($q, $id) => $q->where('category_id', $id));
        $query->when($filters['tag'] ?? null, fn ($q, $id) => $q->whereHas('tags', fn ($tags) => $tags->whereKey($id)));
        match ($filters['sort'] ?? 'newest') {
            'title' => $query->orderBy('title')->orderBy('id'),
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderBy('id'),
        };

        return view('documents.index', [...$this->context($request, 'documents'), 'documents' => $query->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', [Document::class, $request->attributes->get('workspace')]);

        return view('documents.upload', [...$this->context($request, 'upload'), 'document' => null]);
    }

    public function store(DocumentRequest $request, DocumentLibrary $library)
    {
        $document = $library->upload($request->user(), $request->attributes->get('workspace'), $request->file('file'), $request->validated(), draft: true);

        $document->refresh();
        try {
            if ($document->ocr_status === 'idle') {
                app(DocumentProcessingService::class)->request($request->user(), $document, 'ocr');
            } elseif ($request->boolean('analyze_with_ai') && $document->ai_requested && $document->ocr_status === 'ready' && ! $document->ai_run_id && in_array($document->ai_status, ['failed', 'quota_exceeded'], true)) {
                app(DocumentProcessingService::class)->request($request->user(), $document, 'ai');
            }
        } catch (ValidationException $exception) {
            $stage = $document->ocr_status === 'ready' ? 'ai_status' : 'ocr_status';
            $document->forceFill([$stage => $exception instanceof ProcessingQuotaExceeded ? 'quota_exceeded' : 'failed'])->save();
        }

        app(DraftSource::class)->releaseOrQueue($document->id, $document->workspace_id);
        if ($request->expectsJson()) {
            return response()->json((new DraftUploadResource($document))->resolve($request), 201)->header('Cache-Control', 'no-store, private');
        }

        return redirect()->route($document->ai_requested ? 'documents.show' : 'documents.edit',
            ['document' => $document->id, ...($document->ai_requested ? ['review' => 1] : [])])->with('library_status', __('documents.uploaded'));
    }

    public function show(Request $request, string $document)
    {
        $record = $this->document($request, $document);
        if ($request->boolean('review') && ! $record->trashed() && Gate::allows('update', $record)) {
            if ($record->ai_status === 'ready') {
                return redirect()->route('documents.suggestions', ['document' => $record->id]);
            }
            if (in_array($record->ai_status, ['needs_review', 'failed', 'rejected', 'quota_exceeded'], true) || in_array($record->ocr_status, ['empty', 'failed', 'quota_exceeded'], true)) {
                return redirect()->route('documents.edit', ['document' => $record->id]);
            }
        }

        return view('documents.show', [...$this->context($request, 'documents'), 'document' => $record]);
    }

    public function edit(Request $request, string $document)
    {
        $record = $this->document($request, $document);
        Gate::authorize('update', $record);

        return view('documents.form', [...$this->context($request, 'documents'), 'document' => $record]);
    }

    public function update(DocumentRequest $request, string $document, DocumentLibrary $library)
    {
        $record = $this->document($request, $document);
        $library->update($request->user(), $record, $request->validated(), $request->file('file'));
        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('documents.show', ['document' => $record->id])])->header('Cache-Control', 'no-store, private');
        }

        return redirect()->route('documents.show', ['document' => $record->id])->with('library_status', __('documents.saved'));
    }

    public function file(Request $request, string $document)
    {
        $record = $this->document($request, $document);
        abort_if($record->source_released_at, 404);
        $disk = Storage::disk('local');
        if (! ($request->route()->defaults['download'] ?? false) && $record->mime_type === 'application/pdf') {
            $page = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:'.$record->page_count]])['page'] ?? 1;
            $path = dirname($record->storage_path).'/preview-'.$page.'.jpg';
            abort_unless($record->preview_status === 'ready' && $disk->exists($path), 404);

            return $disk->response($path, 'preview.jpg', ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private', 'Content-Security-Policy' => "default-src 'none'"]);
        }
        abort_unless($disk->exists($record->storage_path), 404);
        $headers = ['Content-Type' => $record->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'", 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store, private'];

        return $disk->response($record->storage_path, $record->original_name, $headers, ($request->route()->defaults['download'] ?? false) ? 'attachment' : 'inline');
    }

    public function sourceReceived(Request $request, string $document, DraftSource $source)
    {
        $record = $this->document($request, $document);
        Gate::authorize('update', $record);
        abort_unless($record->isDraft(), 409);
        $data = $request->validate(['bytes' => ['required', 'integer', 'min:1']]);
        abort_unless((int) $data['bytes'] === $record->size_bytes, 422);
        $source->releaseOrQueue($record->id, $record->workspace_id);

        return response()->json(['received' => true])->header('Cache-Control', 'no-store, private');
    }

    public function discard(Request $request, string $document, DocumentLibrary $library)
    {
        $record = $this->document($request, $document);
        Gate::authorize('update', $record);
        abort_unless($library->discardDraft($record), 409);

        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('documents.index')])->header('Cache-Control', 'no-store, private');
        }

        return redirect()->route('documents.index')->with('library_status', __('processing.discarded'));
    }

    public function confirmDeletion(Request $request, string $document)
    {
        $record = $this->document($request, $document);
        Gate::authorize('delete', $record);

        return view('documents.delete', [...$this->context($request, 'documents'), 'document' => $record]);
    }

    public function destroy(Request $request, string $document)
    {
        $candidate = $this->document($request, $document);
        Gate::authorize('delete', $candidate);
        $request->validate(['confirm' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $document) {
            Workspace::lockForUpdate()->findOrFail($request->attributes->get('workspace')->id);
            $record = $this->document($request, $document);
            Gate::authorize('delete', $record);
            app(DocumentProcessingService::class)->cancel($record);
            // Marcaj intern pentru retry de curatare; nu exista restaurare sau cos public.
            $record->forceFill(['status' => 'deleting', 'purge_requested_at' => now()])->save();
            $record->delete();
        }, 3);
        try {
            (new PurgeDocument($candidate->id, $candidate->workspace_id))->handle();
        } catch (\Throwable) {
            PurgeDocument::dispatch($candidate->id, $candidate->workspace_id);

            return redirect()->route('documents.index')->with('library_status', __('documents.delete_pending'));
        }

        return redirect()->route('documents.index')->with('library_status', __('documents.deleted'));
    }
}
