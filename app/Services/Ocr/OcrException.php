<?php

namespace App\Services\Ocr;

class OcrException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('OCR processing error: '.$reason);
    }
}
