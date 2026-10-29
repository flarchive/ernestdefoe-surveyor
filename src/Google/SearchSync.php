<?php

namespace Ernestdefoe\Surveyor\Google;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Settings;
use Ernestdefoe\Surveyor\Site;
use Illuminate\Database\ConnectionInterface;

/**
 * Pulls Search Analytics into Surveyor's own tables: every page's last 28
 * days against the 28 before, its top queries, the site day by day, and a
 * weekly snapshot per page. At most 11 requests a refresh (rows are paged
 * 25 000 at a time, up to 100 000 pages); Google's data is final about
 * three days after the fact, so the window ends three days ago.
 */
class SearchSync
{
    public const ROWS = 25000;
    public const MAX_PAGE_REQUESTS = 4;
    public const QUERIES_PER_PAGE = 20;
    public const HISTORY_WEEKS = 26;
    public const DAYS_KEPT = 490;

    private const CHUNK = 500;

    public function __construct(
        protected Google $google,
        protected ConnectionInterface $db,
        protected Settings $settings,
        protected Site $site,
    ) {
    }

    /** Is the property one this forum can be part of? sc-domain:host, or a URL prefix on this host. */
    public function propertyValid(string $property): bool
    {
        if (preg_match('/^sc-domain:([a-z0-9.-]+)$/i', $property, $m)) {
            $domain = strtolower($m[1]);

            return $this->site->host() === $domain || str_ends_with($this->site->host(), '.'.$domain);
        }

        $parts = parse_url($property);

        return is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && strtolower($parts['host'] ?? '') === $this->site->host()
            && str_ends_with($property, '/');
    }

    public function property(): ?string
    {
        $property = $this->settings->get('google_property');

        return $property !== '' && $this->propertyValid($property) ? $property : null;
    }

    public function due(): bool
    {
        $days = ['daily' => 1, 'weekly' => 7][$this->settings->get('google_refresh')] ?? 0;
        $last = (int) $this->settings->get('google_refreshed_at');

        return $days > 0 && $this->google->account() !== null && $this->property() !== null && $last < time() - $days * 86400 + 1800;
    }

    /** @return string|null an error, or null when everything was refreshed */
    public function refresh(): ?string
    {
        $property = $this->property();
        if ($property === null || $this->google->account() === null) {
            return 'not_connected';
        }

        $end = Carbon::now()->startOfDay()->subDays(3);
        $start = $end->copy()->subDays(27);
        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays(27);

        $current = $this->pages($property, $start, $end);
        if (is_string($current)) {
            return $current;
        }
        $previous = $this->pages($property, $prevStart, $prevEnd);
        if (is_string($previous)) {
            return $previous;
        }

        $queries = $this->query($property, 'queries', $start, $end, ['page', 'query'], 0, 2);
        if (is_string($queries)) {
            return $queries;
        }

        $days = $this->query($property, 'days', $end->copy()->subDays(89), $end, ['date'], 0, 1);
        if (is_string($days)) {
            return $days;
        }

        $this->db->transaction(function () use ($current, $previous, $queries, $days, $end) {
            $this->storePages($current, $previous, $end);
            $this->storeQueries($queries);
            $this->storeDays($days, $end);
            $this->storeHistory($current, $end);
        });

        $this->settings->set('google_refreshed_at', (string) time());

        return null;
    }

    /**
     * Page rows keyed by forum path: clicks, impressions, impression-weighted position.
     *
     * @return array<string, array{clicks: int, impressions: int, position: float}>|string
     */
    private function pages(string $property, Carbon $start, Carbon $end): array|string
    {
        $rows = $this->query($property, 'pages', $start, $end, ['page'], 0, self::MAX_PAGE_REQUESTS);
        if (is_string($rows)) {
            return $rows;
        }

        $pages = [];
        foreach ($rows as $row) {
            $path = $this->site->path((string) ($row['keys'][0] ?? ''));
            if ($path === null) {
                continue;
            }
            $p = &$pages[$path];
            $p ??= ['clicks' => 0, 'impressions' => 0, 'weighted' => 0.0];
            $p['clicks'] += (int) ($row['clicks'] ?? 0);
            $p['impressions'] += (int) ($row['impressions'] ?? 0);
            $p['weighted'] += (float) ($row['position'] ?? 0) * (int) ($row['impressions'] ?? 0);
            unset($p);
        }

        return array_map(fn ($p) => [
            'clicks' => $p['clicks'],
            'impressions' => $p['impressions'],
            'position' => $p['impressions'] > 0 ? $p['weighted'] / $p['impressions'] : 0.0,
        ], $pages);
    }

    /**
     * @param list<string> $dimensions
     * @return list<array<string, mixed>>|string rows, or an error
     */
    private function query(string $property, string $action, Carbon $start, Carbon $end, array $dimensions, int $startRow, int $maxRequests): array|string
    {
        $all = [];

        for ($i = 0; $i < $maxRequests; $i++) {
            $result = $this->google->call('analytics_'.$action, 'POST', Google::API.'/sites/'.Google::siteUrl($property).'/searchAnalytics/query', [
                'startDate' => $start->toDateString(),
                'endDate' => $end->toDateString(),
                'dimensions' => $dimensions,
                'rowLimit' => self::ROWS,
                'startRow' => $startRow + $i * self::ROWS,
                'dataState' => 'final',
            ]);

            if ($result['error'] !== null) {
                return $result['error'];
            }

            $rows = $result['data']['rows'] ?? [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row)) {
                    $all[] = $row;
                }
            }

            if (count(is_array($rows) ? $rows : []) < self::ROWS) {
                break;
            }
        }

        return $all;
    }

    /**
     * @param array<string, array{clicks: int, impressions: int, position: float}> $current
     * @param array<string, array{clicks: int, impressions: int, position: float}> $previous
     */
    private function storePages(array $current, array $previous, Carbon $end): void
    {
        $this->db->table('surveyor_search_pages')->delete();
        $now = Carbon::now();
        $rows = [];

        foreach (array_keys($current + $previous) as $path) {
            $c = $current[$path] ?? null;
            $p = $previous[$path] ?? null;
            $rows[] = [
                'url_hash' => sha1($path),
                'path' => mb_substr($path, 0, 1000),
                'clicks' => $c['clicks'] ?? 0,
                'impressions' => $c['impressions'] ?? 0,
                'position' => $c ? (int) round($c['position'] * 100) : null,
                'prev_clicks' => $p['clicks'] ?? 0,
                'prev_impressions' => $p['impressions'] ?? 0,
                'prev_position' => $p ? (int) round($p['position'] * 100) : null,
                'period_end' => $end->toDateString(),
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->db->table('surveyor_search_pages')->insert($chunk);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function storeQueries(array $rows): void
    {
        $byPage = [];
        foreach ($rows as $row) {
            $path = $this->site->path((string) ($row['keys'][0] ?? ''));
            $query = trim((string) ($row['keys'][1] ?? ''));
            if ($path === null || $query === '') {
                continue;
            }
            $key = sha1($path).'|'.sha1(mb_strtolower($query));
            $existing = $byPage[sha1($path)][$key] ?? null;
            $byPage[sha1($path)][$key] = [
                'url_hash' => sha1($path),
                'query_hash' => sha1(mb_strtolower($query)),
                'query' => mb_substr($query, 0, 500),
                'clicks' => (int) ($row['clicks'] ?? 0) + ($existing['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0) + ($existing['impressions'] ?? 0),
                'position' => (int) round((float) ($row['position'] ?? 0) * 100),
            ];
        }

        $this->db->table('surveyor_search_queries')->delete();
        $insert = [];
        foreach ($byPage as $queries) {
            usort($queries, fn ($a, $b) => $b['impressions'] <=> $a['impressions']);
            array_push($insert, ...array_slice($queries, 0, self::QUERIES_PER_PAGE));
        }

        foreach (array_chunk($insert, self::CHUNK) as $chunk) {
            $this->db->table('surveyor_search_queries')->insert($chunk);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function storeDays(array $rows, Carbon $end): void
    {
        $insert = [];
        foreach ($rows as $row) {
            $day = (string) ($row['keys'][0] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                $insert[] = [
                    'day' => $day,
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'position' => (int) round((float) ($row['position'] ?? 0) * 100),
                ];
            }
        }

        foreach (array_chunk($insert, self::CHUNK) as $chunk) {
            $this->db->table('surveyor_search_days')->upsert($chunk, ['day'], ['clicks', 'impressions', 'position']);
        }

        $this->db->table('surveyor_search_days')->where('day', '<', $end->copy()->subDays(self::DAYS_KEPT)->toDateString())->delete();
    }

    /** @param array<string, array{clicks: int, impressions: int, position: float}> $current */
    private function storeHistory(array $current, Carbon $end): void
    {
        $week = $end->copy()->startOfWeek()->toDateString();
        $rows = [];
        foreach ($current as $path => $c) {
            $rows[] = ['url_hash' => sha1($path), 'week' => $week, 'clicks' => $c['clicks'], 'impressions' => $c['impressions'], 'position' => (int) round($c['position'] * 100)];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->db->table('surveyor_search_history')->upsert($chunk, ['url_hash', 'week'], ['clicks', 'impressions', 'position']);
        }

        $this->db->table('surveyor_search_history')->where('week', '<', $end->copy()->subWeeks(self::HISTORY_WEEKS)->toDateString())->delete();
    }
}
