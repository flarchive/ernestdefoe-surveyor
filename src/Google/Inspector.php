<?php

namespace Ernestdefoe\Surveyor\Google;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Http\ApiLog;
use Ernestdefoe\Surveyor\Settings;
use Ernestdefoe\Surveyor\Site;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * URL Inspection, one page at a time, when an admin asks. Google allows
 * 2 000 a day and 600 a minute per property; Surveyor stops well short:
 * the admin's daily cap (200 by default) and 10 a minute.
 */
class Inspector
{
    public const PER_MINUTE = 10;
    public const MAX_DAILY = 2000;

    public function __construct(
        protected Google $google,
        protected SearchSync $sync,
        protected ConnectionInterface $db,
        protected Settings $settings,
        protected Site $site,
        protected ApiLog $log,
        protected Cache $cache,
    ) {
    }

    public function dailyCap(): int
    {
        return $this->settings->int('inspect_daily', 1, self::MAX_DAILY);
    }

    /** @return array{row: object|null, error: string|null} */
    public function inspect(string $path): array
    {
        $property = $this->sync->property();
        if ($property === null || $this->google->account() === null) {
            return ['row' => null, 'error' => 'not_connected'];
        }

        if ($this->log->today('google', 'inspect') >= $this->dailyCap()) {
            return ['row' => null, 'error' => 'daily_cap'];
        }

        $minute = 'ernestdefoe-surveyor.inspect.'.date('YmdHi');
        $count = (int) $this->cache->get($minute, 0);
        if ($count >= self::PER_MINUTE) {
            return ['row' => null, 'error' => 'minute_cap'];
        }
        $this->cache->put($minute, $count + 1, 120);

        $result = $this->google->call('inspect', 'POST', Google::INSPECT, [
            'inspectionUrl' => $this->site->url($path),
            'siteUrl' => $property,
        ]);

        $index = $result['data']['inspectionResult']['indexStatusResult'] ?? [];
        $rich = $result['data']['inspectionResult']['richResultsResult'] ?? [];
        $index = is_array($index) ? $index : [];
        $rich = is_array($rich) ? $rich : [];
        $text = fn ($v, $len) => is_string($v) && $v !== '' ? mb_substr($v, 0, $len) : null;

        $row = [
            'url_hash' => sha1($path),
            'path' => mb_substr($path, 0, 1000),
            'verdict' => $text($index['verdict'] ?? null, 20),
            'coverage' => $text($index['coverageState'] ?? null, 120),
            'indexing_state' => $text($index['indexingState'] ?? null, 40),
            'fetch_state' => $text($index['pageFetchState'] ?? null, 40),
            'robots_state' => $text($index['robotsTxtState'] ?? null, 20),
            'google_canonical' => $text($index['googleCanonical'] ?? null, 1000),
            'rich_verdict' => $text($rich['verdict'] ?? null, 20),
            'last_crawl_at' => isset($index['lastCrawlTime']) && is_string($index['lastCrawlTime']) ? Carbon::parse($index['lastCrawlTime']) : null,
            'error' => $result['error'] === null ? null : mb_substr($result['error'], 0, 255),
            'inspected_at' => Carbon::now(),
        ];

        $this->db->table('surveyor_inspections')->upsert([$row], ['url_hash'], array_keys(array_diff_key($row, ['url_hash' => 1])));

        return ['row' => $this->db->table('surveyor_inspections')->where('url_hash', sha1($path))->first(), 'error' => $result['error']];
    }
}
