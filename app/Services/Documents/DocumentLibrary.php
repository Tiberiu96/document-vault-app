<?php

namespace App\Services\Documents;

use App\Jobs\Documents\ExpireDocumentDraft;
use App\Jobs\Documents\GenerateDocumentPreview;
use App\Jobs\Documents\PurgeDocument;
use App\Models\Category;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PlanLimitsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentLibrary
{
    public function upload(User $user, Workspace $workspace, UploadedFile $file, array $data, bool $draft = false): Document
    {
        Gate::forUser($user)->authorize('create', [Document::class, $workspace]);
        $inspection = app(FileInspector::class)->inspect($file);
        $id = (string) Str::uuid();
        $path = 'workspaces/'.$workspace->id.'/documents/'.$id.'/original.'.$inspection['extension'];
        try {
            return DB::transaction(function () use ($user, $workspace, $file, $data, $inspection, $id, $path, $draft) {
                $locked = Workspace::lockForUpdate()->findOrFail($workspace->id);
                Gate::forUser($user)->authorize('create', [Document::class, $locked]);
                $duplicate = Document::withTrashed()->where('workspace_id', $locked->id)->where('sha256', $inspection['sha256'])->first();
                if ($duplicate && $duplicate->isDraft() && ! $duplicate->trashed() && $duplicate->uploaded_by === $user->id) {
                    return $duplicate;
                }
                if ($duplicate) {
                    throw ValidationException::withMessages(['file' => __('documents.duplicate')]);
                }
                app(PlanLimitsService::class)->reserveStorage($locked, $inspection['size_bytes']);
                $document = new Document;
                $metadata = $this->metadata($locked, $data);
                $name = mb_substr(preg_replace('/[\x00-\x1F\x7F\\\\\/]/u', '_', $file->getClientOriginalName()), 0, 200);
                $document->forceFill([
                    'id' => $id, 'workspace_id' => $locked->id, 'uploaded_by' => $user->id,
                    'title' => $data['title'], 'original_name' => $name ?: 'document.'.$inspection['extension'],
                    'ai_requested' => ! empty($data['analyze_with_ai']), 'storage_path' => $path, 'status' => $draft ? 'draft' : 'stored', 'preview_status' => $inspection['mime_type'] === 'application/pdf' ? ($draft ? 'awaiting_source' : 'pending') : 'ready',
                    ...array_diff_key($inspection, ['extension' => true]), ...$metadata,
                ])->save();
                $this->syncTags($document, $locked, $data['tags'] ?? [], $data['tag_ids'] ?? []);
                $result = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']);
                if ($result === false) {
                    throw ValidationException::withMessages(['file' => __('documents.storage_error')]);
                }

                if ($draft) {
                    ExpireDocumentDraft::dispatch($document->id, $document->workspace_id)->delay(now()->addDay());
                }
                if (! $draft && $document->mime_type === 'application/pdf') {
                    GenerateDocumentPreview::dispatch($document->id, $document->workspace_id);
                }

                return $document;
            }, 3);
        } catch (\Throwable $exception) {
            $committed = Document::withTrashed()->find($id);
            if ($committed) {
                $committed->forceFill(['preview_status' => 'failed'])->save();

                return $committed;
            }
            Storage::disk('local')->deleteDirectory(dirname($path));
            if ($exception instanceof ValidationException || $exception instanceof AuthorizationException) {
                throw $exception;
            }
            Log::warning('DOCUMENT_STORAGE_OPERATION_FAILED', ['exception_class' => get_class($exception)]);
            throw ValidationException::withMessages(['file' => __('documents.storage_error')]);
        }
    }

    public function update(User $user, Document $document, array $data, ?UploadedFile $source = null): void
    {
        Gate::forUser($user)->authorize('update', $document);
        $inspection = $source ? app(FileInspector::class)->inspect($source) : null;
        $previewRevision = null;
        try {
            DB::transaction(function () use ($user, $document, $data, $source, $inspection, &$previewRevision) {
                $workspace = Workspace::lockForUpdate()->findOrFail($document->workspace_id);
                $fresh = Document::lockForUpdate()->findOrFail($document->id);
                Gate::forUser($user)->authorize('update', $fresh);
                if (isset($data['ai_run_id'])) {
                    $run = ProcessingRun::where('document_id', $fresh->id)->whereKey($data['ai_run_id'])->first();
                    if (! $run || $fresh->ai_run_id !== $run->id || $fresh->ai_status !== 'ready' || $run->base_version !== $fresh->metadata_version || ($run->result['review_required'] ?? true)) {
                        throw ValidationException::withMessages(['processing' => __('processing.stale')]);
                    }
                    $run->forceFill(['accepted_at' => now()])->save();
                    $fresh->forceFill(['ai_status' => 'accepted']);
                }
                if (isset($data['metadata_version']) && (int) $data['metadata_version'] !== $fresh->metadata_version) {
                    throw ValidationException::withMessages(['processing' => __('processing.stale')]);
                }
                $metadata = $this->metadata($workspace, $data);
                // Validarea etichetelor precede scrierea fisierului; rollback la orice eroare.
                $this->syncTags($fresh, $workspace, $data['tags'] ?? [], $data['tag_ids'] ?? []);
                if ($fresh->isDraft()) {
                    if (! $source || ! $inspection) {
                        throw ValidationException::withMessages(['file' => __('processing.source_reselect')]);
                    }
                    if (! hash_equals($fresh->sha256, $inspection['sha256']) || $fresh->mime_type !== $inspection['mime_type'] || $fresh->page_count !== $inspection['page_count']) {
                        throw ValidationException::withMessages(['file' => __('processing.source_mismatch')]);
                    }
                    if ($fresh->source_released_at) {
                        app(PlanLimitsService::class)->reserveStorage($workspace, $inspection['size_bytes']);
                    }
                    if (! Storage::disk('local')->putFileAs(dirname($fresh->storage_path), $source, basename($fresh->storage_path), ['visibility' => 'private'])) {
                        throw ValidationException::withMessages(['file' => __('documents.storage_error')]);
                    }
                    $storedHash = @hash_file('sha256', Storage::disk('local')->path($fresh->storage_path));
                    if (! is_string($storedHash) || ! hash_equals($fresh->sha256, $storedHash)) {
                        throw ValidationException::withMessages(['file' => __('documents.storage_error')]);
                    }
                    $fresh->forceFill(['source_released_at' => null, 'source_revision' => $fresh->source_revision + 1]);
                    if ($fresh->mime_type === 'application/pdf' && $fresh->preview_status !== 'ready') {
                        $fresh->preview_status = 'pending';
                        $previewRevision = $fresh->source_revision;
                    }
                    if (! isset($data['ai_run_id']) && $fresh->ai_status === 'ready') {
                        $fresh->ai_status = 'rejected';
                    }
                } elseif ($source) {
                    throw ValidationException::withMessages(['file' => __('processing.source_mismatch')]);
                }
                $fresh->forceFill(['metadata_version' => $fresh->metadata_version + 1]);
                $fresh->forceFill(['status' => 'stored', 'title' => $data['title'], ...$metadata])->save();
            }, 3);
        } catch (\Throwable $exception) {
            if ($source) {
                app(DraftSource::class)->releaseOrQueue($document->id, $document->workspace_id);
            }
            if ($exception instanceof ValidationException || $exception instanceof AuthorizationException) {
                throw $exception;
            }
            Log::warning('DOCUMENT_STORAGE_OPERATION_FAILED', ['exception_class' => get_class($exception)]);
            throw ValidationException::withMessages(['file' => __('documents.storage_error')]);
        }
        if ($previewRevision !== null) {
            try {
                GenerateDocumentPreview::dispatch($document->id, $document->workspace_id, $previewRevision);
            } catch (\Throwable) {
                Document::whereKey($document->id)->where('source_revision', $previewRevision)->update(['preview_status' => 'failed']);
            }
        }
    }

    public function discardDraft(Document $document, bool $expiredOnly = false): bool
    {
        $discarded = DB::transaction(function () use ($document, $expiredOnly) {
            Workspace::lockForUpdate()->findOrFail($document->workspace_id);
            $record = Document::lockForUpdate()->find($document->id);
            if (! $record || ! $record->isDraft() || ($expiredOnly && $record->created_at->isAfter(now()->subDay()))) {
                return false;
            }
            app(DocumentProcessingService::class)->cancel($record);
            $record->forceFill(['status' => 'discarded', 'purge_requested_at' => now()])->save();
            $record->delete();

            return true;
        }, 3);
        if ($discarded) {
            try {
                (new PurgeDocument($document->id, $document->workspace_id))->handle();
            } catch (\Throwable) {
                // Fisierul ramane inaccesibil; curatarea si eliberarea cotei se reincearca.
                PurgeDocument::dispatch($document->id, $document->workspace_id);
            }
        }

        return $discarded;
    }

    private function metadata(Workspace $workspace, array $data): array
    {
        $category = empty($data['category_id']) ? null : Category::forWorkspace($workspace)->find($data['category_id']);
        if (! empty($data['category_id']) && ! $category) {
            throw ValidationException::withMessages(['category_id' => __('documents.invalid_category')]);
        }
        $amount = $data['amount'] ?? null;
        $minor = null;
        if ($amount !== null && $amount !== '') {
            [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
            $minor = ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
        }

        return [
            'category_id' => $category?->id, 'summary' => $data['summary'] ?? null, 'description' => $data['description'] ?? null,
            'supplier' => $data['supplier'] ?? null, 'amount_minor' => $minor,
            'currency' => $minor === null ? null : $data['currency'],
            'issued_on' => $data['issued_on'] ?? null, 'expires_on' => $data['expires_on'] ?? null,
        ];
    }

    private function syncTags(Document $document, Workspace $workspace, array $names, array $selected): void
    {
        $tags = Tag::forWorkspace($workspace)->get();
        $ids = $tags->whereIn('id', $selected)->pluck('id')->all();
        if (count(array_unique($selected)) !== count($ids)) {
            throw ValidationException::withMessages(['tags' => __('documents.invalid_tags')]);
        }
        foreach ($names as $name) {
            $normalized = mb_strtolower(trim($name));
            if ($normalized === '') {
                continue;
            }
            $predefined = $tags->first(fn ($tag) => $tag->system_key && mb_strtolower($tag->label()) === $normalized);
            $tag = $predefined ?? Tag::unguarded(fn () => Tag::firstOrCreate(['workspace_id' => $workspace->id, 'normalized_name' => $normalized], ['name' => trim($name)]));
            $ids[] = $tag->id;
        }
        if (count(array_unique($ids)) > 10) {
            throw ValidationException::withMessages(['tags' => __('documents.invalid_tags')]);
        }
        $document->tags()->sync(array_unique($ids));
    }
}
