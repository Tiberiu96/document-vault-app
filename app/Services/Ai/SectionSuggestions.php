<?php

namespace App\Services\Ai;

class SectionSuggestions
{
    public function merge(array $results): array
    {
        $warnings = array_values(array_unique(array_merge(...array_column($results, 'warnings'))));
        $review = in_array(true, array_column($results, 'review_required'), true);
        $fields = [];
        foreach (['category_key', 'supplier', 'amount', 'currency', 'issued_on', 'expires_on'] as $field) {
            $values = array_values(array_unique(array_filter(array_map(fn ($result) => $result['fields'][$field] ?? null, $results), fn ($value) => $value !== null)));
            if (count($values) > 1) {
                $review = true;
                $warnings[] = 'inconsistent';
            }
            $fields[$field] = $values[0] ?? null;
        }
        $fields['summary'] = collect($results)->map(fn ($result) => $result['fields']['summary'] ?? null)->first(fn ($value) => $value !== null);
        $fields['tag_keys'] = array_values(array_unique(array_merge(...array_map(fn ($result) => $result['fields']['tag_keys'] ?? [], $results))));
        if (count($fields['tag_keys']) > 3) {
            $review = true;
            $warnings[] = 'inconsistent';
        }
        if ($fields['category_key'] === null) {
            $review = true;
            $warnings[] = 'uncertain';
        }

        return ['review_required' => $review, 'warnings' => array_values(array_unique($warnings)),
            'fields' => $review ? [] : $fields, 'partial' => false, 'sections' => count($results)];
    }
}
