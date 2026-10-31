<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\UsesAuthenticatedWorkspace;
use App\Models\Document;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListMyDocuments implements Tool
{
    use UsesAuthenticatedWorkspace;

    public function description(): Stringable|string
    {
        return 'List the signed-in user documents from the active workspace. Use this before guessing a document ID.';
    }

    public function handle(Request $request): Stringable|string
    {
        $workspace = $this->workspace();
        $documents = Document::where('workspace_id', $workspace->id)->where('status', 'stored')
            ->with('category')->latest()->limit(20)->get()
            ->map(fn (Document $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'category' => $document->category?->name,
                'expires_on' => $document->expires_on?->toDateString(),
                'has_reminder' => $document->reminder()->where('status', 'active')->exists(),
            ])->all();

        return json_encode(['documents' => $documents], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
