<?php

namespace Ernestdefoe\Surveyor\Speed;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Http\ApiClient;
use Ernestdefoe\Surveyor\Http\ApiLog;
use Ernestdefoe\Surveyor\Security\Secrets;
use Ernestdefoe\Surveyor\Settings;
use Ernestdefoe\Surveyor\Site;
use Illuminate\Database\ConnectionInterface;

/**
 * PageSpeed Insights: Lighthouse lab results and Chrome UX Report field data
 * (Core Web Vitals) for one page and strategy. Measurements are queued and
 * made one at a time from the scheduler (each takes 10–40 seconds at Google),
 * within the admin's daily cap. An API key is optional; without one Google's
 * shared quota is small, so the default cap is low.
 */
class PageSpeed
{
    public const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    public const STRATEGIES = ['mobile', 'desktop'];
    public const KEEP = 20;
    public const MAX_DAILY = 2000;

    public function __construct(
        protected ApiClient $http,
        protected ApiLog $log,
        protected Secrets $secrets,
        protected Settings $settings,
        protected Site $site,
        protected ConnectionInterface $db,
    ) {
    }

    public function dailyCap(): int
    {
        return $this->settings->int('speed_daily', 1, self::MAX_DAILY);
    }

    public function queue(string $path, ?array $strategies = null): int
    {
        $rows = [];
        foreach ($strategies ?? self::STRATEGIES as $strategy) {
            if (in_array($strategy, self::STRATEGIES, true)) {
                $rows[] = ['url_hash' => sha1($path), 'strategy' => $strategy, 'path' => mb_substr($path, 0, 1000), 'queued_at' => Carbon::now()];
            }
        }

        return $rows ? $this->db->table('surveyor_speed_queue')->insertOrIgnore($rows) : 0;
    }

    /** Measures the oldest queued page. @return bool whether a measurement was attempted */
    public function next(): bool
    {
        if ($this->log->pausedUntil('pagespeed') !== null || $this->log->today('pagespeed') >= $this->dailyCap()) {
            return false;
        }

        $row = $this->db->table('surveyor_speed_queue')->orderBy('queued_at')->first();
        if (! $row) {
            return false;
        }

        $this->db->table('surveyor_speed_queue')->where('url_hash', $row->url_hash)->where('strategy', $row->strategy)->delete();
        $this->measure($row->path, $row->strategy);

        return true;
    }

    public function measure(string $path, string $strategy): object
    {
        $query = ['url' => $this->site->url($path), 'strategy' => $strategy, 'category' => 'performance'];
        if ($key = $this->secrets->get(Secrets::PAGESPEED)) {
            $query['key'] = $key;
        }

        // Google needs up to a minute for a slow page; this runs from the scheduler, not a page view.
        $response = $this->http->send('GET', self::ENDPOINT.'?'.http_build_query($query), ['Accept' => 'application/json'], null, 70);
        $data = json_decode($response['body'], true);
        $data = is_array($data) ? $data : [];
        $status = $response['status'];
        $error = null;

        if ($status !== 200) {
            $error = mb_substr((string) ($data['error']['message'] ?? ($status === 0 ? 'request failed' : 'error '.$status)), 0, 250);
            $this->log->pauseFor('pagespeed', $status === 400 || $status === 500 ? 0 : $status);
        }

        $this->log->record('pagespeed', 'measure', $status, $error);

        $row = ['url_hash' => sha1($path), 'path' => mb_substr($path, 0, 1000), 'strategy' => $strategy, 'error' => $error, 'measured_at' => Carbon::now()] + self::read($data);

        $id = $this->db->table('surveyor_speed')->insertGetId($row);

        // Keep the newest measurements per page and strategy.
        $old = $this->db->table('surveyor_speed')->where('url_hash', sha1($path))->where('strategy', $strategy)
            ->orderByDesc('id')->offset(self::KEEP)->limit(100)->pluck('id')->all();
        if ($old) {
            $this->db->table('surveyor_speed')->whereIn('id', $old)->delete();
        }

        return $this->db->table('surveyor_speed')->where('id', $id)->first() ?? (object) $row;
    }

    /**
     * The numbers worth keeping from a PageSpeed answer.
     *
     * @param array<string, mixed> $data
     * @return array<string, int|string|null>
     */
    public static function read(array $data): array
    {
        $audits = $data['lighthouseResult']['audits'] ?? [];
        $num = fn (string $audit) => isset($audits[$audit]['numericValue']) && is_numeric($audits[$audit]['numericValue']) ? (int) round((float) $audits[$audit]['numericValue']) : null;
        $score = $data['lighthouseResult']['categories']['performance']['score'] ?? null;
        $cls = $audits['cumulative-layout-shift']['numericValue'] ?? null;

        // The page's own field data when Chrome has enough of it, else the whole site's.
        $scope = ! empty($data['loadingExperience']['metrics']) ? 'url' : (! empty($data['originLoadingExperience']['metrics']) ? 'origin' : null);
        $field = $scope === 'url' ? $data['loadingExperience'] : ($scope === 'origin' ? $data['originLoadingExperience'] : []);
        $metric = fn (string $name) => isset($field['metrics'][$name]['percentile']) ? (int) $field['metrics'][$name]['percentile'] : null;
        $category = $field['overall_category'] ?? null;

        return [
            'score' => is_numeric($score) ? (int) round((float) $score * 100) : null,
            'lcp_ms' => $num('largest-contentful-paint'),
            'fcp_ms' => $num('first-contentful-paint'),
            'tbt_ms' => $num('total-blocking-time'),
            'si_ms' => $num('speed-index'),
            'cls' => is_numeric($cls) ? (int) round((float) $cls * 1000) : null,
            'field_lcp_ms' => $metric('LARGEST_CONTENTFUL_PAINT_MS'),
            'field_inp_ms' => $metric('INTERACTION_TO_NEXT_PAINT'),
            // The report gives CLS × 100; stored × 1000 like the lab value.
            'field_cls' => ($v = $metric('CUMULATIVE_LAYOUT_SHIFT_SCORE')) === null ? null : $v * 10,
            'field_category' => is_string($category) && in_array($category, ['FAST', 'AVERAGE', 'SLOW'], true) ? $category : null,
            'field_scope' => $scope,
        ];
    }

    /**
     * Pages a scheduled run measures: those with the most search clicks, else
     * the home page and the most-linked pages from the audit.
     *
     * @return list<string>
     */
    public function scheduledPages(int $limit): array
    {
        $paths = $this->db->table('surveyor_search_pages')->orderByDesc('clicks')->limit($limit)->pluck('path')->all();

        if (count($paths) < $limit) {
            $linked = $this->db->table('surveyor_pages')
                ->where('classification', 'ok')
                ->whereNotNull('score')
                ->orderByRaw("CASE WHEN found_via = 'home' THEN 0 ELSE 1 END")
                ->orderByDesc(fn ($q) => $q->from('surveyor_links')->selectRaw('COUNT(*)')->whereColumn('surveyor_links.target_id', 'surveyor_pages.id'))
                ->limit($limit)
                ->pluck('path')
                ->all();
            $paths = array_values(array_unique(array_merge($paths, $linked)));
        }

        return array_slice($paths, 0, $limit);
    }
}
