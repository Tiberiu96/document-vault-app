<?php

namespace Tests\Unit;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\OllamaContext;
use App\Services\Ai\SectionSuggestions;
use Tests\TestCase;

class OllamaContextTest extends TestCase
{
    public function test_sections_preserve_every_character_and_respect_encoded_budget(): void
    {
        config(['ai.ollama.context_length' => 16384, 'ai.ollama.max_output_tokens' => 1024, 'ai.ollama.max_sections' => 4]);
        $text = str_repeat("Document árvíztűrő Български \\\"\n", 360);
        $context = app(OllamaContext::class);
        $sections = $context->sections($text);
        $this->assertGreaterThan(1, count($sections));
        $reconstructed = array_shift($sections);
        foreach ($sections as $piece) {
            $this->assertTrue(mb_check_encoding($piece, 'UTF-8'));
            $this->assertLessThanOrEqual($context->inputBudget() - 6000, strlen(json_encode($piece, JSON_UNESCAPED_UNICODE)));
            $overlap = 0;
            for ($size = 1; $size <= min(256, mb_strlen($piece)); $size++) {
                if (mb_substr($reconstructed, -$size) === mb_substr($piece, 0, $size)) {
                    $overlap = $size;
                }
            }
            $reconstructed .= mb_substr($piece, $overlap);
        }
        $this->assertSame($text, $reconstructed);
    }

    public function test_overflow_is_explicit_instead_of_dropping_sections(): void
    {
        config(['ai.ollama.context_length' => 16384, 'ai.ollama.max_output_tokens' => 1024]);
        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('PROMPT_TOO_LARGE');
        app(OllamaContext::class)->sections(str_repeat('Document ', 20000));
    }

    public function test_conflicting_fields_or_unvalidated_sections_block_all_suggestions(): void
    {
        $first = ['review_required' => false, 'warnings' => [], 'fields' => ['category_key' => 'insurance', 'amount' => '100.00', 'currency' => 'RON', 'summary' => 'Polita.', 'tag_keys' => []]];
        $second = $first;
        $second['fields']['amount'] = '200.00';
        $result = app(SectionSuggestions::class)->merge([$first, $second]);
        $this->assertTrue($result['review_required']);
        $this->assertSame([], $result['fields']);
        $this->assertContains('inconsistent', $result['warnings']);
        $result = app(SectionSuggestions::class)->merge([$first, ['review_required' => true, 'warnings' => ['low_quality'], 'fields' => []]]);
        $this->assertTrue($result['review_required']);
        $this->assertSame([], $result['fields']);
    }
}
