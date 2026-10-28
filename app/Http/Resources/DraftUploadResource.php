<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DraftUploadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $parameters = ['document' => $this->id];

        return [
            'id' => $this->id,
            'file_name' => $this->original_name,
            'file_type' => $this->mime_type,
            'file_bytes' => $this->size_bytes,
            'source_url' => route('documents.download', $parameters),
            'source_received_url' => route('documents.source-received', $parameters),
            'review_requested' => $request->boolean('analyze_with_ai'),
            'status_url' => route('documents.processing-status', $parameters),
            'show_url' => route('documents.show', [...$parameters, 'review' => 1]),
            'review_url' => route('documents.suggestions', $parameters),
            'edit_url' => route('documents.edit', $parameters),
            'discard_url' => route('documents.discard', $parameters),
            'index_url' => route('documents.index'),
            'messages' => [
                'title' => __('processing.title'), 'progress' => __('processing.in_progress'),
                'ocr' => __('processing.ocr'), 'ai' => __('processing.ai'),
                'failed' => __('processing.offline'), 'cancel' => __('documents.cancel'),
                'retry' => __('documents.refresh'),
            ],
        ];
    }
}
