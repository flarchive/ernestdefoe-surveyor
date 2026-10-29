<?php

namespace Ernestdefoe\Surveyor\Api;

use Ernestdefoe\Surveyor\Crawl\Auditor;
use Ernestdefoe\Surveyor\Crawl\Scorer;
use Ernestdefoe\Surveyor\Diagnostics\Checks;
use Ernestdefoe\Surveyor\Google\Opportunities;
use Ernestdefoe\Surveyor\Guard\Regressions;
use Ernestdefoe\Surveyor\IndexNow\Sender;
use Ernestdefoe\Surveyor\Settings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Psr\Http\Message\ServerRequestInterface;

/** The dashboard: a handful of counts, all from Surveyor's own tables (no fetching). */
class SummaryController extends Controller
{
    protected array $actions = ['show'];

    public function __construct(
        protected ConnectionInterface $db,
        protected Auditor $auditor,
        protected Settings $settings,
        protected Sender $sender,
        protected Cache $cache,
        protected Regressions $regressions,
    ) {
    }

    /** @return array<string, mixed>|null clicks and opportunities, once Search Console has data */
    private function search(): ?array
    {
        $totals = $this->db->table('surveyor_search_pages')->selectRaw('COUNT(*) as pages, SUM(clicks) as clicks, SUM(prev_clicks) as prev_clicks')->first();

        if (! $totals || (int) $totals->pages === 0) {
            return null;
        }

        $high = 0;
        $rows = $this->db->table('surveyor_search_pages')
            ->where(fn ($q) => $q->where('impressions', '>=', Opportunities::MIN_IMPRESSIONS_STRIKING)->orWhere('prev_clicks', '>=', Opportunities::MIN_PREV_CLICKS))
            ->limit(5000)->get();
        foreach ($rows as $row) {
            foreach (Opportunities::find($row) as $o) {
                $high += $o['priority'] === 'high' ? 1 : 0;
            }
        }

        return ['clicks' => (int) $totals->clicks, 'prevClicks' => (int) $totals->prev_clicks, 'highOpportunities' => $high];
    }

    /** @return array<string, mixed> */
    protected function show(ServerRequestInterface $request): array
    {
        $pages = $this->db->table('surveyor_pages')->whereNotNull('crawled_at');

        $classes = (clone $pages)->groupBy('classification')->get(['classification', $this->db->raw('COUNT(*) as total')])
            ->mapWithKeys(fn ($r) => [(string) $r->classification => (int) $r->total]);

        // One pass over the table for every issue count.
        $issues = [];
        $select = [];
        $bindings = [];
        foreach (array_keys(Scorer::WEIGHTS) as $i => $code) {
            $select[] = "SUM(CASE WHEN issues LIKE ? THEN 1 ELSE 0 END) AS i$i";
            $bindings[] = '%"'.$code.'"%';
        }
        $row = (clone $pages)->selectRaw(implode(', ', $select), $bindings)->first();
        foreach (array_keys(Scorer::WEIGHTS) as $i => $code) {
            $issues[$code] = (int) ($row->{"i$i"} ?? 0);
        }

        $duplicates = [];
        foreach (['title' => 'title_hash', 'description' => 'description_hash'] as $kind => $column) {
            $groups = $this->db->table('surveyor_pages')
                ->select($column)
                ->whereNotNull($column)
                ->where(fn ($q) => $q->whereNull('canonical_self')->orWhere('canonical_self', true))
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1');
            $duplicates[$kind] = $this->db->query()->fromSub($groups, 'groups')->count();
        }

        $links = $this->db->table('surveyor_pages')
            ->whereIn('classification', LinksController::PROBLEMS)
            ->where('link_ignored', false)
            ->whereExists(fn (Builder $q) => $q->from('surveyor_links')->whereColumn('surveyor_links.target_id', 'surveyor_pages.id'))
            ->groupBy('classification')
            ->get(['classification', $this->db->raw('COUNT(*) as total')])
            ->mapWithKeys(fn ($r) => [(string) $r->classification => (int) $r->total]);

        $runs = $this->db->table('surveyor_runs')->orderByDesc('id')->limit(12)->get();
        $current = $this->auditor->current();
        $tick = (int) $this->cache->get(Checks::TICK_KEY, 0);

        return [
            'run' => $current ? AuditController::run($current) : null,
            'runs' => $runs->map(fn ($r) => AuditController::run($r))->all(),
            'pages' => array_sum($classes->all()),
            'average' => ($avg = (clone $pages)->whereNotNull('score')->avg('score')) === null ? null : (int) round((float) $avg),
            'classes' => $classes->all(),
            'issues' => $issues,
            'duplicates' => $duplicates,
            'links' => $links->all(),
            'indexnow' => [
                'enabled' => $this->settings->indexNowActive(),
                'pending' => $this->db->table('surveyor_indexnow_queue')->where('pending', true)->count(),
                'last' => self::time($this->db->table('surveyor_indexnow_log')->orderByDesc('id')->value('created_at')),
                'backoff' => $this->sender->backoffUntil() !== null,
            ],
            'missing' => $this->db->table('detour_missing')->where('ignored', false)->count(),
            'search' => $this->search(),
            'regressions' => count($this->regressions->all()),
            'schedulerTick' => $tick ? date(DATE_ATOM, $tick) : null,
            'frequency' => $this->settings->get('audit_frequency'),
        ];
    }
}
