<?php

namespace App\Http\Controllers;

use App\Services\LanguageCatalog;

class PublicDiscoveryController extends Controller
{
    public function sitemap()
    {
        $urls = [];
        foreach (app(LanguageCatalog::class)->available() as $language) {
            foreach (config('vault.public_pages') as $page) {
                $urls[] = $this->publicUrl($language->locale, $page);
            }
        }

        return response()->view('seo.sitemap', compact('urls'))->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots()
    {
        $content = app()->environment('production')
            ? "User-agent: *\nAllow: /\nSitemap: ".rtrim(config('app.url'), '/')."/sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n";

        return response($content)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function llms()
    {
        $content = '# '.config('app.name')."\n\n> Document organisation for individuals and businesses. Currently in development.\n\nPublic product information and an illustrative demo are available. Document uploads, OCR, AI, reminders and subscriptions are planned; they are not available yet.\n\n## Public pages\n";
        foreach (app(LanguageCatalog::class)->available() as $language) {
            foreach (config('vault.public_pages') as $page) {
                $content .= '- ['.$language->native_name.' — '.$page.']('.$this->publicUrl($language->locale, $page).")\n";
            }
        }

        return response($content)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    private function publicUrl(string $locale, string $page): string
    {
        $slug = trans('routes.'.$page, [], $locale);

        return rtrim(config('app.url'), '/').'/'.$locale.($slug ? '/'.$slug : '');
    }
}
