<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\UsesAuthenticatedWorkspace;
use App\Models\Document;
use App\Services\ReminderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateReminder implements Tool
{
    use UsesAuthenticatedWorkspace;

    public function description(): Stringable|string
    {
        return 'Create or refresh a reminder for one document in the active workspace. A future date is required only when the document has no expiration date.';
    }

    public function handle(Request $request): Stringable|string
    {
        $data = $request->validate([
            'document_id' => ['required', 'uuid'],
            'date' => ['nullable', 'date_format:Y-m-d', 'after:'.now(config('reminders.timezone'))->toDateString()],
        ]);
        $workspace = $this->workspace();
        $user = $this->user();

        $document = DB::transaction(function () use ($data, $workspace, $user) {
            $document = Document::where('workspace_id', $workspace->id)->where('status', 'stored')->lockForUpdate()->findOrFail($data['document_id']);
            Gate::forUser($user)->authorize('update', $document);
            if (! $document->expires_on && empty($data['date'])) {
                throw ValidationException::withMessages(['date' => __('reminders.future_date')]);
            }
            if (! $document->expires_on) {
                $document->forceFill(['expires_on' => $data['date'], 'metadata_version' => $document->metadata_version + 1])->save();
            }

            return $document;
        }, 3);

        $reminder = app(ReminderService::class)->syncDocument($document, $user, app()->getLocale());

        return json_encode([
            'created' => $reminder !== null,
            'document_id' => $document->id,
            'due_on' => $reminder?->due_on?->toDateString(),
        ], JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->string()->description('Document UUID returned by list_my_documents')->required(),
            'date' => $schema->string()->description('Optional future date in YYYY-MM-DD format'),
        ];
    }
}
