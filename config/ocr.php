<?php

return [
    'enabled' => (bool) env('OCR_ENABLED', true),
    'tesseract' => env('TESSERACT_BINARY', 'tesseract'),
    'ocrmypdf' => env('OCRMYPDF_BINARY', 'ocrmypdf'),
    'pdftotext' => env('PDFTOTEXT_BINARY', 'pdftotext'),
];
