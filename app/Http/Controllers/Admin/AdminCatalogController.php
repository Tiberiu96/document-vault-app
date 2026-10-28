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
        return view('admin.catalog', ['catalog' => ($request->routeIs('admin.categories*') ? 'categories' : 'tags'), 'items' => $this->model($request)::whereNull('workspace_id')->whereNotNull('system_key')->orderBy('system_key')->get()]);
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
