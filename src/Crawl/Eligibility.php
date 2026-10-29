<?php

namespace Ernestdefoe\Surveyor\Crawl;

use Ernestdefoe\Surveyor\Rules\RuleSet;
use Ernestdefoe\Surveyor\Site;

/**
 * Which of the forum's addresses the crawler may fetch. Private and personal
 * routes never are (a guest only sees a sign-in screen there anyway), nor
 * files, nor anything robots.txt or the Rules keep out.
 */
class Eligibility
{
    /** Paths below the forum's folder that are never crawled, with everything under them. */
    public const DENIED = [
        '/admin', '/api', '/assets', '/auth', '/login', '/logout', '/global-logout', '/register',
        '/confirm', '/reset', '/settings', '/notifications', '/maintenance', '/messages', '/flags',
        '/following', '/gdpr', '/bookmarks', '/drafts', '/unsubscribe',
    ];

    private const FILE = '~\.(?:js|mjs|css|map|png|jpe?g|gif|webp|avif|svg|ico|bmp|tiff?|mp[34]|m4a|webm|ogg|wav|pdf|zip|gz|tgz|tar|rar|7z|txt|xml|json|rss|atom|woff2?|ttf|otf|eot|exe|dmg|apk|csv)$~i';

    public function __construct(
        protected Site $site,
        protected RuleSet $rules,
    ) {
    }

    /**
     * Null when the path may be crawled, else why not: denied | file | robots | rule.
     *
     * @param string $path below the forum's folder, without a query
     */
    public function reason(string $path, ?Robots $robots = null, string $area = 'audit'): ?string
    {
        if ($path === '' || $path[0] !== '/' || strlen($path) > 1000) {
            return 'denied';
        }

        $lower = strtolower(rawurldecode($path));
        foreach (self::DENIED as $prefix) {
            if ($lower === $prefix || str_starts_with($lower, $prefix.'/')) {
                return 'denied';
            }
        }

        if (preg_match(self::FILE, $path)) {
            return 'file';
        }

        if ($robots !== null && ! $robots->allows($this->site->basePath().$path)) {
            return 'robots';
        }

        return $this->rules->excludes($area, $path);
    }

    /**
     * The forum path of an absolute address when it is on the forum and may
     * be crawled; the query string is dropped (sorting and paging variants
     * of one page are the same page to an audit).
     */
    public function path(string $url, ?Robots $robots = null, string $area = 'audit'): ?string
    {
        $path = $this->site->path($url);

        if ($path === null || $this->reason($path, $robots, $area) !== null) {
            return null;
        }

        return $path;
    }
}
