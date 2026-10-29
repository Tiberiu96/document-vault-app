<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminCatalogRequest;
use App\Models\Category;
use App\Models\Tag;
use App\Services\Admin\AdminAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminCatalogController extends Controller
{
    private function model(Request $request): string
    {
        return ($request->routeIs('admin.categories*') ? 'categories' : 'tags') === 'categories' ? Category::class : Tag::class;
    }

    public function index(Request $request)
    {
        $catalog = $request->routeIs('admin.categories*') ? 'categories' : 'tags';
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'scope' => ['nullable', 'in:personal,business']]);
        $query = $this->model($request)::whereNull('workspace_id')->whereNotNull('system_key');
        if ($request->filled('q')) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('system_key', 'like', $term));
        }
        if ($catalog === 'categories' && ! empty($filters['scope'])) {
            $query->where('scope', $filters['scope']);
        }

        return view('admin.catalog', ['catalog' => $catalog, 'items' => $query->orderBy('system_key')->orderBy('id')->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function save(AdminCatalogRequest $request, ?string $item = null)
    {
        try {
            DB::transaction(function () use ($request, $item) {
                $class = $this->model($request);
                $record = $item ? $class::whereNull('workspace_id')->whereNotNull('system_key')->lockForUpdate()->findOrFail($item) : new $class;
                if (! $item) {
                    $record->forceFill(['workspace_id' => null, 'system_key' => $request->validated('system_key')]);
                    if ($class === Category::class) {
                        $record->scope = $request->validated('scope');
                    }
                }
                $record->forceFill(['name' => $request->validated('translations.ro'), 'normalized_name' => mb_strtolower($request->validated('translations.ro')), 'translations' => $request->validated('translations')])->save();
                AdminAudit::record(Auth::guard('admin')->user(), $item ? 'catalog.updated' : 'catalog.created', ($request->routeIs('admin.categories*') ? 'categories' : 'tags'), $record->id);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['system_key' => 'Cheia exista deja in acest catalog.']);
        }

        return back()->with('admin_status', 'Catalog actualizat.');
    }

    public function destroy(Request $request, string $item)
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $item) {
            $record = $this->model($request)::whereNull('workspace_id')->whereNotNull('system_key')->lockForUpdate()->findOrFail($item);
            $record->delete();
            AdminAudit::record(Auth::guard('admin')->user(), 'catalog.deleted', ($request->routeIs('admin.categories*') ? 'categories' : 'tags'), $item);
        }, 3);

        return back()->with('admin_status', 'Element sters. Documentele au fost pastrate.');
    }
}
