<?php

namespace App\Services\Ocr;

class TextQuality
{
    public function good(string $text): bool
    {
        $length = mb_strlen(trim($text));
        preg_match_all('/[\p{L}\p{N}]/u', $text, $characters);
        preg_match_all('/\p{L}{2,}/u', $text, $words);

        return $length >= 40 && count($characters[0]) >= 30 && count($words[0]) >= 4
            && count($characters[0]) / max(1, $length) >= 0.45 && substr_count($text, "\u{FFFD}") < 3;
    }
}
