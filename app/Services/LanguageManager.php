<?php

namespace App\Services;

use App\Models\Language;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LanguageManager
{
    // Interfata admin va apela acest serviciu numai dupa auth + MFA.
    public function updateAvailability(User $actor, int $id, bool $active, bool $ready): void
    {
        abort_unless($actor->is_super_admin, 403);
        DB::transaction(function () use ($id, $active, $ready): void {
            $languages = Language::orderBy('id')->lockForUpdate()->get();
            $language = $languages->find($id);
            abort_unless($language, 404);
            if ($language->is_default && (! $active || ! $ready)) {
                throw ValidationException::withMessages(['language' => 'Selecteaza mai intai o alta limba implicita activa si pregatita.']);
            }
            if ($active && (! $ready || ! array_key_exists($language->locale, config('vault.locales')))) {
                throw ValidationException::withMessages(['language' => 'Traducerile si rutele trebuie livrate inainte de activare.']);
            }
            $language->forceFill(['is_active' => $active, 'translation_ready' => $ready])->save();
        });
    }

    public function makeDefault(User $actor, int $id): void
    {
        abort_unless($actor->is_super_admin, 403);
        DB::transaction(function () use ($id): void {
            $languages = Language::orderBy('id')->lockForUpdate()->get();
            $language = $languages->find($id);
            abort_unless($language, 404);
            if (! $language->is_active || ! $language->translation_ready || ! array_key_exists($language->locale, config('vault.locales'))) {
                throw ValidationException::withMessages(['language' => 'Limba implicita trebuie sa fie activa si pregatita.']);
            }
            if ($language->is_default) {
                return;
            }
            Language::where('is_default', true)->update(['is_default' => false]);
            $language->forceFill(['is_default' => true])->save();
        });
    }
}
