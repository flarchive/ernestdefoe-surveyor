<?php

namespace Ernestdefoe\Surveyor;

use Flarum\Http\UrlGenerator;

/**
 * The forum's own address, and the one place that decides whether a URL is
 * on it. Everything Surveyor fetches goes through here: same scheme, same
 * host, same port, inside the forum's folder. Nothing else is ever requested.
 */
class Site
{
    private string $scheme;

    private string $host;

    private ?int $port;

    /** The forum's folder, '' at the root of the host, else '/forum'. */
    private string $basePath;

    public function __construct(UrlGenerator $url)
    {
        $this->setBase($url->to('forum')->base());
    }

    public function setBase(string $base): void
    {
        $parts = parse_url($base) ?: [];
        $this->scheme = strtolower($parts['scheme'] ?? 'http');
        $this->host = strtolower($parts['host'] ?? 'localhost');
        $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
        $this->basePath = rtrim($parts['path'] ?? '', '/');
    }

    public function base(): string
    {
        return $this->origin().$this->basePath;
    }

    public function origin(): string
    {
        return $this->scheme.'://'.$this->host.($this->port !== null ? ':'.$this->port : '');
    }

    public function host(): string
    {
        return $this->host;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function isHttps(): bool
    {
        return $this->scheme === 'https';
    }

    /** The full address of a path below the forum ('/d/1' => 'https://host/forum/d/1'). */
    public function url(string $path): string
    {
        return $this->base().($path === '/' ? '/' : $path);
    }

    /**
     * Resolves a link as a browser would, against the page it is on, and
     * returns it absolute without its fragment, or null when it is not an
     * http(s) address at all.
     */
    public function resolve(string $href, string $pageUrl): ?string
    {
        $href = trim($href);

        if ($href === '' || $href[0] === '#' || strlen($href) > 2000 || preg_match('/[\x00-\x1F\x7F\\\\]/', $href)) {
            return null;
        }

        $href = explode('#', $href, 2)[0];

        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $href) && ! preg_match('~^https?:~i', $href)) {
            return null; // mailto:, javascript:, tel:, data: …
        }

        if (str_starts_with($href, '//')) {
            return strtolower((string) parse_url($pageUrl, PHP_URL_SCHEME)).':'.$href;
        }

        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }

        $page = parse_url($pageUrl) ?: [];
        $origin = ($page['scheme'] ?? 'http').'://'.($page['host'] ?? '').(isset($page['port']) ? ':'.$page['port'] : '');

        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        if (str_starts_with($href, '?')) {
            return $origin.($page['path'] ?? '/').$href;
        }

        $dir = preg_replace('~/[^/]*$~', '/', $page['path'] ?? '/') ?: '/';

        return $origin.self::dots($dir.$href);
    }

    /**
     * The path below the forum's folder ('/d/1-hello') when the address is on
     * this forum, else null. Refused: another scheme, host or port, user info
     * in the address, an IP address that is not the forum's own host, and
     * anything outside the forum's folder.
     */
    public function path(string $url): ?string
    {
        $parts = parse_url($url);

        if (! $parts || isset($parts['user']) || isset($parts['pass']) || ! isset($parts['host'])) {
            return null;
        }

        if (strtolower($parts['scheme'] ?? '') !== $this->scheme || strtolower($parts['host']) !== $this->host) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $default = $this->scheme === 'https' ? 443 : 80;
        if (($port ?? $default) !== ($this->port ?? $default)) {
            return null;
        }

        $path = self::dots($parts['path'] ?? '/');

        if ($this->basePath !== '') {
            if ($path !== $this->basePath && ! str_starts_with($path, $this->basePath.'/')) {
                return null;
            }
            $path = substr($path, strlen($this->basePath));
        }

        return $path === '' ? '/' : $path;
    }

    /** Is this an address on the forum? */
    public function owns(string $url): bool
    {
        return $this->path($url) !== null;
    }

    /** robots.txt lives at the root of the host, even when the forum is in a folder. */
    public function robotsUrl(): string
    {
        return $this->origin().'/robots.txt';
    }

    /** May Surveyor request this address? The forum's own pages, and its host's robots.txt. */
    public function fetchable(string $url): bool
    {
        return $url === $this->robotsUrl() || $this->owns($url);
    }

    /** Removes `.` and `..` segments, as a browser does. */
    public static function dots(string $path): string
    {
        if (! str_contains($path, '.')) {
            return $path;
        }

        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($out);
            } elseif ($segment !== '.') {
                $out[] = $segment;
            }
        }

        $result = implode('/', $out);

        return str_starts_with($result, '/') ? $result : '/'.$result;
    }
}
