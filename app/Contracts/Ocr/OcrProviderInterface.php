<?php

namespace App\Contracts\Ocr;

use App\Models\Document;

interface OcrProviderInterface
{
    public function extract(Document $document): array;
}
