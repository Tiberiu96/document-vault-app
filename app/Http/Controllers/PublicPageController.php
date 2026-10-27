<?php

namespace App\Http\Controllers;

use App\Http\Requests\DemoSearchRequest;
use App\Services\WebsiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class PublicPageController extends Controller
{
    public function index(Request $request)
    {
        $page = Route::currentBaseName();

        return view('pages.'.$page, $this->data($request, $page));
    }

    public function demo(DemoSearchRequest $request)
    {
        $validated = $request->validated();
        $documents = collect([
            ['key' => 'energy', 'category' => 'bills', 'amount' => '245,67 RON', 'date' => '28.10.2026', 'type' => 'PDF'],
            ['key' => 'rent', 'category' => 'contracts', 'amount' => '—', 'date' => '26.10.2026', 'type' => 'PDF'],
            ['key' => 'car', 'category' => 'insurance', 'amount' => '1.245,00 RON', 'date' => '22.10.2026', 'type' => 'PDF'],
            ['key' => 'internet', 'category' => 'bills', 'amount' => '89,00 RON', 'date' => '18.10.2026', 'type' => 'PDF'],
        ])->filter(fn ($document) => (empty($validated['q']) || mb_stripos(__('site.documents.'.$document['key']), $validated['q']) !== false)
            && (empty($validated['category']) || $validated['category'] === 'all' || $validated['category'] === $document['category']));

        return view('pages.demo', [...$this->data($request, 'demo'), 'documents' => $documents]);
    }

    private function data(Request $request, string $page): array
    {
        return app(WebsiteContext::class)->forPage($request, $page);
    }
}
