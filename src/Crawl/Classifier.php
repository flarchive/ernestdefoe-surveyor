<?php

namespace Ernestdefoe\Surveyor\Crawl;

use Ernestdefoe\Surveyor\Http\Page;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * What a link's destination is to a visitor who is not signed in.
 *
 * Flarum answers 404 to a guest for a discussion, tag or profile they may not
 * see, so a 404 alone does not mean "broken": when the thing still exists,
 * the link is "restricted" (it works for members), not broken.
 */
class Classifier
{
    public const OK = 'ok';
    public const REDIRECT = 'redirect';
    public const BROKEN = 'broken';
    public const RESTRICTED = 'restricted';
    public const CHALLENGE = 'challenge';
    public const FAILED = 'failed';

    public const ALL = [self::OK, self::REDIRECT, self::BROKEN, self::RESTRICTED, self::CHALLENGE, self::FAILED];

    public function __construct(
        protected ConnectionInterface $db,
    ) {
    }

    public function classify(Page $page, string $path): string
    {
        if ($page->failed()) {
            return self::FAILED;
        }

        if (self::challenge($page)) {
            return self::CHALLENGE;
        }

        $status = $page->status;

        if (in_array($status, [404, 410], true)) {
            return $this->exists($path) ? self::RESTRICTED : self::BROKEN;
        }

        if ($page->redirects !== [] && $status < 400) {
            return self::REDIRECT;
        }

        if ($status >= 200 && $status < 300) {
            return self::OK;
        }

        if ($status === 401 || $status === 403) {
            return self::RESTRICTED;
        }

        return self::FAILED;
    }

    /** A security service's "checking your browser" page instead of the forum's. */
    public static function challenge(Page $page): bool
    {
        if (str_contains(strtolower($page->header('cf-mitigated')), 'challenge')) {
            return true;
        }

        if (! in_array($page->status, [403, 429, 503], true)) {
            return false;
        }

        return (bool) preg_match(
            '/challenge-platform|cf-chl|cf_chl_|Just a moment\.\.\.|Attention Required!|cf-turnstile|g-recaptcha|h-captcha|hcaptcha|sucuri|ddos-guard|captcha-delivery/i',
            substr($page->body, 0, 30000)
        );
    }

    /** Does the discussion, tag or member a 404'd path names still exist? */
    public function exists(string $path): bool
    {
        $segment = fn (int $i) => rawurldecode(explode('/', $path)[$i] ?? '');

        try {
            if (preg_match('~^/d/(\d+)~', $path, $m)) {
                return Discussion::query()->whereKey((int) $m[1])->whereNull('hidden_at')->exists();
            }

            if (str_starts_with($path, '/u/') && $segment(2) !== '') {
                return User::query()->where('username', $segment(2))->exists();
            }

            if (str_starts_with($path, '/t/') && $segment(2) !== '') {
                return $this->db->table('tags')->where('slug', $segment(2))->exists();
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
