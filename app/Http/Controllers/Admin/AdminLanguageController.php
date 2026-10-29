<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminLanguageRequest;
use App\Models\Language;
use App\Services\Admin\AdminAudit;
use App\Services\LanguageManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminLanguageController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'status' => ['nullable', 'in:active,inactive']]);
        $query = Language::query();
        if ($request->filled('q')) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('locale', 'like', $term)->orWhere('display_name', 'like', $term)->orWhere('native_name', 'like', $term));
        }
        if (! empty($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'active');
        }

        return view('admin.languages', ['languages' => $query->orderBy('id')->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function save(AdminLanguageRequest $request, LanguageManager $manager, ?string $language = null)
    {
        try {
            DB::transaction(function () use ($request, $manager, $language) {
                $actor = Auth::guard('admin')->user();
                if ($language) {
                    $manager->updateAvailability($actor, (int) $language, $request->boolean('is_active'), $request->boolean('translation_ready'));
                    $record = Language::findOrFail($language);
                } else {
                    if ($request->boolean('is_active') || $request->boolean('translation_ready')) {
                        throw ValidationException::withMessages(['locale' => 'O limba noua se creeaza inactiva, pana la livrarea traducerilor si rutelor.']);
                    }
                    $record = new Language;
                    $record->forceFill(['locale' => $request->validated('locale'), 'is_active' => false, 'is_default' => false, 'translation_ready' => false]);
                }
                $currency = config('vault.locales.'.$record->locale);
                if ($currency && $currency !== $request->validated('default_currency')) {
                    throw ValidationException::withMessages(['default_currency' => 'Moneda pentru aceasta limba este stabilita de configuratia livrata: '.$currency]);
                }
                $record->forceFill($request->safe()->only(['display_name', 'native_name', 'default_currency']))->save();
                AdminAudit::record($actor, $language ? 'language.updated' : 'language.created', 'language', $record->id);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['locale' => 'Aceasta limba exista deja.']);
        }

        return back()->with('admin_status', 'Limba actualizata.');
    }

    public function makeDefault(Request $request, string $language, LanguageManager $manager)
    {
        DB::transaction(function () use ($language, $manager) {
            $manager->makeDefault(Auth::guard('admin')->user(), (int) $language);
            AdminAudit::record(Auth::guard('admin')->user(), 'language.default_changed', 'language', $language);
        }, 3);

        return back()->with('admin_status', 'Limba implicita actualizata.');
    }

    public function destroy(Request $request, string $language)
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        DB::transaction(function () use ($language) {
            $record = Language::orderBy('id')->lockForUpdate()->get()->find($language);
            abort_unless($record, 404);
            if ($record->is_default || $record->is_active || array_key_exists($record->locale, config('vault.locales'))) {
                throw ValidationException::withMessages(['language' => 'Dezactiveaza limba mai intai. Limbile livrate in manifest se elimina numai prin deploy.']);
            }
            $record->delete();
            AdminAudit::record(Auth::guard('admin')->user(), 'language.deleted', 'language', $language);
        }, 3);

        return back()->with('admin_status', 'Limba stearsa.');
    }
}
