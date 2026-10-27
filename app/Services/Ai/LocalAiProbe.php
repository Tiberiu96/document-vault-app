<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiProviderInterface;
use Illuminate\Support\Facades\Validator;

class LocalAiProbe
{
    public function run(string $prompt, string $locale): string
    {
        $language = ['ro' => 'Romanian without diacritics', 'en' => 'English', 'hu' => 'Hungarian with correct accents', 'bg' => 'Bulgarian in Cyrillic'][$locale] ?? 'Romanian without diacritics';
        $data = app(AiProviderInterface::class)->structured([
            ['role' => 'system', 'content' => 'You are an assistant for a document management application. Answer briefly in '.$language.'. Return JSON only with a reply string. You have no access to files, accounts, external tools or the internet. Do not invent document facts.'],
            ['role' => 'user', 'content' => $prompt],
        ], ['type' => 'object', 'properties' => ['reply' => ['type' => 'string', 'maxLength' => 2000]], 'required' => ['reply'], 'additionalProperties' => false]);
        $validator = Validator::make(['result' => $data], ['result' => ['required', 'array:reply'], 'result.reply' => ['required', 'string', 'max:2000']]);
        if ($validator->fails()) {
            throw new AiProviderException('INVALID_AI_RESPONSE');
        }

        return $data['reply'];
    }
}
