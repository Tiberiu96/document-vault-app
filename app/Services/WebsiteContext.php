<?php

namespace App\Services;

use Illuminate\Http\Request;

class WebsiteContext
{
    public function forPage(Request $request, string $page): array
    {
        $catalog = app(LanguageCatalog::class);

        return [
            'page' => $page,
            'languages' => $catalog->available(),
            'defaultLocale' => $catalog->defaultLocale(),
            'currency' => $request->attributes->get('display_currency'),
            'indexable' => in_array($page, config('vault.public_pages'), true),
        ];
    }
}
