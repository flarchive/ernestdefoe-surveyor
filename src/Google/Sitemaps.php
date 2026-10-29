<?php

namespace Ernestdefoe\Surveyor\Google;

use Ernestdefoe\Surveyor\Site;

/** FoF Sitemap's sitemap, as Search Console knows it: list and submit. */
class Sitemaps
{
    public function __construct(
        protected Google $google,
        protected SearchSync $sync,
        protected Site $site,
    ) {
    }

    public function sitemapUrl(): string
    {
        return $this->site->url('/sitemap.xml');
    }

    /** @return array{data: list<array<string, mixed>>, error: string|null} */
    public function list(): array
    {
        $property = $this->sync->property();
        if ($property === null) {
            return ['data' => [], 'error' => 'not_connected'];
        }

        $result = $this->google->call('sitemaps', 'GET', Google::API.'/sites/'.Google::siteUrl($property).'/sitemaps');
        $list = [];

        foreach ((array) ($result['data']['sitemap'] ?? []) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $list[] = [
                'path' => (string) ($s['path'] ?? ''),
                'lastSubmitted' => $s['lastSubmitted'] ?? null,
                'lastDownloaded' => $s['lastDownloaded'] ?? null,
                'pending' => (bool) ($s['isPending'] ?? false),
                'errors' => (int) ($s['errors'] ?? 0),
                'warnings' => (int) ($s['warnings'] ?? 0),
            ];
        }

        return ['data' => $list, 'error' => $result['error']];
    }

    /** @return string|null an error, or null once Google accepted it */
    public function submit(): ?string
    {
        $property = $this->sync->property();
        if ($property === null) {
            return 'not_connected';
        }

        return $this->google->call(
            'sitemap_submit',
            'PUT',
            Google::API.'/sites/'.Google::siteUrl($property).'/sitemaps/'.rawurlencode($this->sitemapUrl()),
            null,
            Google::WRITE
        )['error'];
    }
}
