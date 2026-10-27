<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentRequest;
use App\Jobs\Documents\PurgeDocument;
use App\Models\Category;
use App\Models\Document;
use App\Models\Tag;
use App\Models\Workspace;
use App\Services\Documents\DocumentLibrary;
use App\Services\Documents\DocumentProcessingService;
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

    private function document(Request $request, string $id, bool $trashed = false): Document
    {
        $query = Document::where('workspace_id', $request->attributes->get('workspace')->id)->with(['category', 'tags']);
        if ($trashed) {
            $query->withTrashed();
        }
        $document = $query->findOrFail($id);
        Gate::authorize('view', $document);

        return $document;
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'integer'], 'tag' => ['nullable', 'integer'], 'trash' => ['nullable', 'in:1'], 'sort' => ['nullable', 'in:newest,oldest,title']]);
        $query = Document::where('workspace_id', $request->attributes->get('workspace')->id)->where('status', 'stored')->with(['category', 'tags']);
        if ($request->boolean('trash')) {
            $query->onlyTrashed();
        }
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

        try {
            app(DocumentProcessingService::class)->request($request->user(), $document, 'ocr');
        } catch (ValidationException) {
            $document->forceFill(['ocr_status' => 'failed'])->save();
        }

        return redirect()->route($document->ai_requested ? 'documents.show' : 'documents.edit',
            ['document' => $document->id, ...($document->ai_requested ? ['review' => 1] : [])])->with('library_status', __('documents.uploaded'));
    }

    public function show(Request $request, string $document)
    {
        $record = $this->document($request, $document, true);
        if ($request->boolean('review') && ! $record->trashed() && Gate::allows('update', $record)) {
            if ($record->ai_status === 'ready') {
                return redirect()->route('documents.suggestions', ['document' => $record->id]);
            }
            if (in_array($record->ai_status, ['needs_review', 'failed', 'rejected'], true) || in_array($record->ocr_status, ['empty', 'failed'], true)) {
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
        $library->update($request->user(), $record, $request->validated());

        return redirect()->route('documents.show', ['document' => $record->id])->with('library_status', __('documents.saved'));
    }

    public function file(Request $request, string $document)
    {
        $record = $this->document($request, $document);
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

    public function discard(Request $request, string $document, DocumentLibrary $library)
    {
        $record = $this->document($request, $document);
        Gate::authorize('update', $record);
        abort_unless($library->discardDraft($record), 409);

        return redirect()->route('documents.index')->with('library_status', __('processing.discarded'));
    }

    public function destroy(Request $request, string $document, DocumentLibrary $library)
    {
        $candidate = $this->document($request, $document);
        if ($candidate->isDraft()) {
            Gate::authorize('update', $candidate);
            abort_unless($library->discardDraft($candidate), 409);

            return redirect()->route('documents.index')->with('library_status', __('processing.discarded'));
        }
        DB::transaction(function () use ($request, $document) {
            Workspace::lockForUpdate()->findOrFail($request->attributes->get('workspace')->id);
            $record = $this->document($request, $document);
            Gate::authorize('delete', $record);
            $record->delete();
        });

        return redirect()->route('documents.index')->with('library_status', __('documents.trashed'));
    }

    public function restore(Request $request, string $document)
    {
        DB::transaction(function () use ($request, $document) {
            Workspace::lockForUpdate()->findOrFail($request->attributes->get('workspace')->id);
            $record = $this->document($request, $document, true);
            Gate::authorize('update', $record);
            abort_if($record->purge_requested_at, 409);
            $record->restore();
        });

        return redirect()->route('documents.show', ['document' => $document])->with('library_status', __('documents.restored'));
    }

    public function purge(Request $request, string $document)
    {
        $request->validate(['confirm' => ['accepted']]);
        DB::transaction(function () use ($request, $document) {
            Workspace::lockForUpdate()->findOrFail($request->attributes->get('workspace')->id);
            $record = $this->document($request, $document, true);
            Gate::authorize('manage', [Document::class, $record->workspace]);
            abort_unless($record->trashed(), 409);
            $record->forceFill(['purge_requested_at' => now()])->save();
            PurgeDocument::dispatch($record->id, $record->workspace_id);
        });

        return redirect()->route('documents.index', ['trash' => 1])->with('library_status', __('documents.purge_queued'));
    }
}
