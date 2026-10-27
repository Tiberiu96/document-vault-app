<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $hasDefault = Language::where('is_default', true)->exists();
            foreach ([['ro', 'Romanian', 'Romana', 'RON'], ['en', 'English', 'English', 'EUR'], ['hu', 'Hungarian', 'Magyar', 'HUF'], ['bg', 'Bulgarian', 'Български', 'EUR']] as [$locale, $name, $native, $currency]) {
                if (! Language::where('locale', $locale)->exists()) {
                    $language = new Language;
                    $language->forceFill([
                        'locale' => $locale, 'display_name' => $name, 'native_name' => $native,
                        'default_currency' => $currency, 'is_active' => true,
                        'is_default' => ! $hasDefault && $locale === 'ro', 'translation_ready' => true,
                    ])->save();
                }
            }
        });
    }
}
