<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Ernestdefoe\Surveyor\Http\Fetcher;
use Ernestdefoe\Surveyor\Http\Page;

/** Answers from a map of address => Page. Anything else is a 404. Never touches the network. */
class FakeFetcher implements Fetcher
{
    /** @var array<string, Page> */
    public array $pages = [];

    /** @var list<string> */
    public array $requested = [];

    public function get(string $url, int $maxBytes = Page::MAX_BYTES, ?int $timeout = null): Page
    {
        $this->requested[] = $url;

        return $this->pages[$url] ?? new Page($url, 404, ['content-type' => 'text/html'], '<html><head><title>Not found</title></head><body></body></html>', $url);
    }

    /** @param array<string, string> $headers */
    public function html(string $url, string $html, int $status = 200, array $headers = [], int $ms = 50): void
    {
        $this->pages[$url] = new Page($url, $status, $headers + ['content-type' => 'text/html; charset=utf-8'], $html, $url, [], $ms, strlen($html));
    }

    public function text(string $url, string $body, int $status = 200, string $type = 'text/plain'): void
    {
        $this->pages[$url] = new Page($url, $status, ['content-type' => $type], $body, $url, [], 5, strlen($body));
    }
}
