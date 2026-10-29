<?php

namespace Ernestdefoe\Surveyor\Http;

use Ernestdefoe\Surveyor\Settings;
use Ernestdefoe\Surveyor\Site;
use Composer\InstalledVersions;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Throwable;

/**
 * Plain GET requests with no cookies, a clear User-Agent and hard limits.
 * Redirects are followed by hand, and only while they stay on the forum: a
 * Location on another host is recorded, never requested.
 */
class GuzzleFetcher implements Fetcher
{
    public const MAX_REDIRECTS = 5;

    private ?Client $client = null;

    public function __construct(
        protected Site $site,
        protected Settings $settings,
    ) {
    }

    public static function userAgent(Site $site): string
    {
        try {
            $version = InstalledVersions::getPrettyVersion('ernestdefoe/surveyor') ?? 'dev';
        } catch (Throwable) {
            $version = 'dev';
        }

        return 'Surveyor/'.ltrim($version, 'v').' (+'.$site->base().')';
    }

    public function get(string $url, int $maxBytes = Page::MAX_BYTES, ?int $timeout = null): Page
    {
        $start = hrtime(true);
        $redirects = [];
        $current = $url;

        try {
            for ($hop = 0; ; $hop++) {
                if (! $this->site->fetchable($current)) {
                    // Never reached: get() is only called with the forum's own addresses.
                    return new Page($url, 0, error: 'off_host', ms: self::ms($start));
                }

                $response = $this->client()->get($current, [
                    RequestOptions::STREAM => true,
                    RequestOptions::TIMEOUT => $timeout ?? $this->settings->timeout(),
                ]);
                $status = $response->getStatusCode();
                $headers = [];
                foreach ($response->getHeaders() as $name => $values) {
                    $headers[strtolower($name)] = implode(', ', $values);
                }

                $location = $response->getHeaderLine('Location');

                if ($status >= 300 && $status < 400 && $location !== '') {
                    $next = $this->site->resolve($location, $current);
                    $redirects[] = ['status' => $status, 'location' => $next ?? $location];
                    $response->getBody()->close();

                    // Off the forum, too many hops, or a loop: stop and report it.
                    if ($next === null || ! $this->site->owns($next) || $hop + 1 >= self::MAX_REDIRECTS || $next === $current) {
                        return new Page($url, $status, $headers, '', $next ?? $location, $redirects, self::ms($start));
                    }

                    $current = $next;

                    continue;
                }

                $body = '';
                $stream = $response->getBody();
                while (! $stream->eof() && strlen($body) < $maxBytes) {
                    $body .= $stream->read(65536);
                }
                $bytes = strlen($body);
                $stream->close();

                return new Page($url, $status, $headers, substr($body, 0, $maxBytes), $current, $redirects, self::ms($start), $bytes);
            }
        } catch (Throwable $e) {
            $error = str_contains(strtolower($e->getMessage()), 'timed out') ? 'timeout' : 'request_failed';

            return new Page($url, 0, error: $error, redirects: $redirects, ms: self::ms($start));
        }
    }

    private function client(): Client
    {
        return $this->client ??= new Client([
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::COOKIES => false,
            RequestOptions::CONNECT_TIMEOUT => 5,
            RequestOptions::HEADERS => [
                'User-Agent' => self::userAgent($this->site),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8',
            ],
        ]);
    }

    private static function ms(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / 1e6);
    }
}
