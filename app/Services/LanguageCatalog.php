<?php

namespace App\Services;

use App\Models\Language;
use Illuminate\Database\Eloquent\Collection;

class LanguageCatalog
{
    public function available(): Collection
    {
        return Language::where('is_active', true)->where('translation_ready', true)
            ->whereIn('locale', array_keys(config('vault.locales')))->orderBy('id')->get();
    }

    public function defaultLocale(): string
    {
        $language = $this->available()->firstWhere('is_default', true);
        abort_unless($language, 503);

        return $language->locale;
    }
}
