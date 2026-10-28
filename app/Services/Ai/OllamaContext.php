<?php

namespace App\Services\Ai;

class OllamaContext
{
    public function inputBudget(): int
    {
        // Un byte per token este o limita conservatoare, nu un tokenizer exact.
        return max(0, (int) config('ai.ollama.context_length') - (int) config('ai.ollama.max_output_tokens') - 1024);
    }

    public function sections(string $text): array
    {
        // Rezerva pentru instructiuni, catalog, schema JSON si metadate de sectiune.
        $budget = $this->inputBudget() - 6000;
        if ($budget < 256) {
            throw new AiProviderException('PROMPT_TOO_LARGE');
        }
        $size = fn (string $value) => strlen(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($size($text) <= $budget) {
            return [$text];
        }
        $sections = [];
        $start = 0;
        $length = mb_strlen($text);
        $maximum = max(1, min(4, (int) config('ai.ollama.max_sections', 4)));
        while ($start < $length) {
            if (count($sections) >= $maximum) {
                // Verifica toate sectiunile inainte de prima inferenta; fara omisiuni.
                throw new AiProviderException('PROMPT_TOO_LARGE');
            }
            $low = 1;
            $high = min($length - $start, $budget);
            $take = 0;
            while ($low <= $high) {
                $middle = intdiv($low + $high, 2);
                if ($size(mb_substr($text, $start, $middle)) <= $budget) {
                    $take = $middle;
                    $low = $middle + 1;
                } else {
                    $high = $middle - 1;
                }
            }
            if ($take === 0) {
                throw new AiProviderException('PROMPT_TOO_LARGE');
            }
            $piece = mb_substr($text, $start, $take);
            if ($start + $take < $length) {
                $newline = mb_strrpos($piece, "\n");
                if ($newline !== false && $newline > $take * 0.7) {
                    $take = $newline + 1;
                    $piece = mb_substr($piece, 0, $take);
                }
            }
            $sections[] = $piece;
            if ($start + $take >= $length) {
                break;
            }
            $start += $take - min(256, intdiv($take, 4));
        }

        return $sections;
    }
}
