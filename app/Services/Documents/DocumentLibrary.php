<?php

namespace App\Services\Documents;

use App\Jobs\Documents\GenerateDocumentPreview;
use App\Models\Category;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PlanLimitsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentLibrary
{
    public function upload(User $user, Workspace $workspace, UploadedFile $file, array $data): Document
    {
        Gate::forUser($user)->authorize('create', [Document::class, $workspace]);
        $inspection = app(FileInspector::class)->inspect($file);
        $id = (string) Str::uuid();
        $path = 'workspaces/'.$workspace->id.'/documents/'.$id.'/original.'.$inspection['extension'];
        try {
            return DB::transaction(function () use ($user, $workspace, $file, $data, $inspection, $id, $path) {
                $locked = Workspace::lockForUpdate()->findOrFail($workspace->id);
                Gate::forUser($user)->authorize('create', [Document::class, $locked]);
                if (Document::withTrashed()->where('workspace_id', $locked->id)->where('sha256', $inspection['sha256'])->exists()) {
                    throw ValidationException::withMessages(['file' => __('documents.duplicate')]);
                }
                app(PlanLimitsService::class)->reserveStorage($locked, $inspection['size_bytes']);
                $document = new Document;
                $metadata = $this->metadata($locked, $data);
                $name = mb_substr(preg_replace('/[\x00-\x1F\x7F\\\\\/]/u', '_', $file->getClientOriginalName()), 0, 200);
                $document->forceFill([
                    'id' => $id, 'workspace_id' => $locked->id, 'uploaded_by' => $user->id,
                    'title' => $data['title'], 'original_name' => $name ?: 'document.'.$inspection['extension'],
                    'ai_requested' => ! empty($data['analyze_with_ai']), 'storage_path' => $path, 'status' => 'stored', 'preview_status' => $inspection['mime_type'] === 'application/pdf' ? 'pending' : 'ready',
                    ...array_diff_key($inspection, ['extension' => true]), ...$metadata,
                ])->save();
                $this->syncTags($document, $locked, $data['tags'] ?? [], $data['tag_ids'] ?? []);
                $result = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']);
                if ($result === false) {
                    throw ValidationException::withMessages(['file' => __('documents.storage_error')]);
                }

                if ($document->mime_type === 'application/pdf') {
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
            throw $exception;
        }
    }

    public function update(User $user, Document $document, array $data): void
    {
        DB::transaction(function () use ($user, $document, $data) {
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
            $fresh->forceFill(['metadata_version' => $fresh->metadata_version + 1]);
            $fresh->forceFill(['title' => $data['title'], ...$this->metadata($workspace, $data)])->save();
            $this->syncTags($fresh, $workspace, $data['tags'] ?? [], $data['tag_ids'] ?? []);
        }, 3);
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
