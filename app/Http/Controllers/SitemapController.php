<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\City;
use App\Models\Listing;
use App\Services\SeoMetadata;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(SeoMetadata $seo): Response
    {
        $urls = [$seo->url('sitemaps/pages.xml')];
        $pages = (int) ceil(Listing::published()->count() / 1000);
        for ($page = 1; $page <= $pages; $page++) {
            $urls[] = $seo->url('sitemaps/listings/'.$page.'.xml');
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $xml .= '<sitemap><loc>'.$this->escape($url).'</loc></sitemap>';
        }

        return $this->xml($xml.'</sitemapindex>');
    }

    public function pages(SeoMetadata $seo): Response
    {
        $urls = [$seo->url()];
        foreach (City::whereIn('id', Listing::published()->select('city_id'))->get(['slug']) as $city) {
            $urls[] = $seo->url('browse/'.$city->slug);
        }
        foreach (Category::where('is_active', true)->whereIn('id', Listing::published()->select('category_id'))->get(['slug']) as $category) {
            $urls[] = $seo->url('category/'.$category->slug);
        }

        return $this->urlset(array_map(fn ($url) => ['url' => $url], $urls));
    }

    public function listings(SeoMetadata $seo, int $page): Response
    {
        abort_if($page < 1 || $page > max(1, (int) ceil(Listing::published()->count() / 1000)), 404);
        $listings = Listing::published()->orderBy('id')->skip(($page - 1) * 1000)->take(1000)->get(['slug', 'updated_at']);

        return $this->urlset($listings->map(fn (Listing $listing) => ['url' => $seo->url('ad/'.$listing->slug), 'lastmod' => $listing->updated_at->toIso8601String()])->all());
    }

    /** @param array<int,array<string,string>> $urls */
    private function urlset(array $urls): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $entry) {
            $xml .= '<url><loc>'.$this->escape($entry['url']).'</loc>';
            if (isset($entry['lastmod'])) {
                $xml .= '<lastmod>'.$this->escape($entry['lastmod']).'</lastmod>';
            }
            $xml .= '</url>';
        }

        return $this->xml($xml.'</urlset>');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xml(string $xml): Response
    {
        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'no-cache, private']);
    }

    public function robots(SeoMetadata $seo): Response
    {
        return response("User-agent: *\nDisallow: /admin\nDisallow: /settings\nDisallow: /messages\nDisallow: /my-listings\nDisallow: /listings/\nSitemap: ".$seo->url('sitemap.xml')."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
