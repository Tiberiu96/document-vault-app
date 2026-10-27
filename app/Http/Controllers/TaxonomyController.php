<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaxonomyRequest;
use App\Models\Category;
use App\Models\Document;
use App\Models\Tag;
use App\Models\Workspace;
use App\Services\WebsiteContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TaxonomyController extends Controller
{
    public function index(Request $request)
    {
        $workspace = $request->attributes->get('workspace');

        return view('documents.categories', [
            ...app(WebsiteContext::class)->forPage($request, 'documents'), 'section' => 'categories',
            'workspace' => $workspace, 'workspaces' => $request->user()->workspaces()->get()->filter(fn ($w) => Gate::allows('view', $w)),
            'categories' => Category::forWorkspace($workspace)->get(), 'tags' => Tag::forWorkspace($workspace)->orderBy('name')->get(),
        ]);
    }

    public function save(TaxonomyRequest $request)
    {
        $item = $request->route('item');
        try {
            DB::transaction(function () use ($request, $item) {
                $workspace = Workspace::lockForUpdate()->findOrFail($request->attributes->get('workspace')->id);
                Gate::authorize('manage', [Document::class, $workspace]);
                $class = $request->route()->defaults['taxonomy'] === 'tag' ? Tag::class : Category::class;
                $record = $item ? $class::where('workspace_id', $workspace->id)->findOrFail($item) : new $class;
                $record->forceFill(['workspace_id' => $workspace->id, 'name' => $request->validated('name'), 'normalized_name' => mb_strtolower($request->validated('name'))])->save();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('documents.name_exists')]);
        }

        return redirect()->route('categories.index')->with('library_status', __('documents.saved'));
    }

    public function destroy(Request $request, string $item)
    {
        DB::transaction(function () use ($request, $item) {
            $workspace = Workspace::lockForUpdate()->findOrFail($request->attributes->get('workspace')->id);
            Gate::authorize('manage', [Document::class, $workspace]);
            $class = $request->route()->defaults['taxonomy'] === 'tag' ? Tag::class : Category::class;
            $class::where('workspace_id', $workspace->id)->findOrFail($item)->delete();
        });

        return redirect()->route('categories.index')->with('library_status', __('documents.taxonomy_deleted'));
    }
}
