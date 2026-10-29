<?php

namespace App\Http\Controllers;

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

    private function data(Request $request, string $page): array
    {
        return app(WebsiteContext::class)->forPage($request, $page);
    }
}
