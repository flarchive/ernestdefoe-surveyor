<?php

namespace Ernestdefoe\Surveyor\Guard;

use Illuminate\Database\ConnectionInterface;

/**
 * Pages that got worse, against their last healthy measurement:
 *
 *  - broken:      answered 200 in an earlier audit, now does not
 *  - score:       audit score at least 10 points below its best of the
 *                 last five audits
 *  - visibility:  search clicks (or, with few clicks, impressions) down 30%+
 *                 on the 28 days before
 *  - performance: PageSpeed score 10+ points below the best of its last five
 *                 measurements (same strategy), or LCP 25% slower
 *
 * Read from Surveyor's own tables when the admin opens the list: nothing is
 * fetched, nothing runs on the forum.
 */
class Regressions
{
    public const SCORE_DROP = 10;
    public const VISIBILITY_DROP = 0.3;
    public const MIN_CLICKS = 10;
    public const MIN_IMPRESSIONS = 200;
    public const SPEED_DROP = 10;
    public const LCP_WORSE = 1.25;
    public const LOOKBACK = 5;
    public const LIMIT = 200;

    public function __construct(
        protected ConnectionInterface $db,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_merge($this->audit(), $this->visibility(), $this->performance());
    }

    /** @return list<array<string, mixed>> */
    public function audit(): array
    {
        $runs = $this->db->table('surveyor_runs')->where('status', 'done')->orderByDesc('id')->limit(self::LOOKBACK + 1)->pluck('id')->all();
        if (count($runs) < 2) {
            return [];
        }

        $latest = array_shift($runs);
        $found = [];

        $now = $this->db->table('surveyor_scores')
            ->join('surveyor_pages', 'surveyor_pages.id', '=', 'surveyor_scores.page_id')
            ->where('surveyor_scores.run_id', $latest)
            ->get(['surveyor_scores.page_id', 'surveyor_scores.score', 'surveyor_scores.status_code', 'surveyor_pages.path'])
            ->keyBy('page_id');

        $before = $this->db->table('surveyor_scores')
            ->whereIn('run_id', $runs)
            ->whereIn('page_id', $now->keys()->all())
            ->groupBy('page_id')
            ->get(['page_id', $this->db->raw('MAX(score) as best'), $this->db->raw('MAX(CASE WHEN status_code = 200 THEN 1 ELSE 0 END) as was_ok')])
            ->keyBy('page_id');

        foreach ($now as $pageId => $row) {
            $old = $before->get($pageId);
            if (! $old) {
                continue;
            }
            if ((int) $old->was_ok === 1 && (int) $row->status_code !== 200) {
                $found[] = ['kind' => 'broken', 'path' => $row->path, 'pageId' => (int) $pageId, 'before' => 200, 'now' => $row->status_code === null ? null : (int) $row->status_code];
            } elseif ($old->best !== null && $row->score !== null && (int) $old->best - (int) $row->score >= self::SCORE_DROP) {
                $found[] = ['kind' => 'score', 'path' => $row->path, 'pageId' => (int) $pageId, 'before' => (int) $old->best, 'now' => (int) $row->score];
            }
        }

        usort($found, fn ($a, $b) => (($b['before'] ?? 0) - ($b['now'] ?? 0)) <=> (($a['before'] ?? 0) - ($a['now'] ?? 0)));

        return array_slice($found, 0, self::LIMIT);
    }

    /** @return list<array<string, mixed>> */
    public function visibility(): array
    {
        $rows = $this->db->table('surveyor_search_pages')
            ->where(fn ($q) => $q->where('prev_clicks', '>=', self::MIN_CLICKS)->orWhere('prev_impressions', '>=', self::MIN_IMPRESSIONS))
            ->orderByDesc('prev_clicks')
            ->limit(2000)
            ->get();

        $found = [];
        foreach ($rows as $row) {
            [$metric, $before, $now] = (int) $row->prev_clicks >= self::MIN_CLICKS
                ? ['clicks', (int) $row->prev_clicks, (int) $row->clicks]
                : ['impressions', (int) $row->prev_impressions, (int) $row->impressions];

            if ($now <= $before * (1 - self::VISIBILITY_DROP)) {
                $found[] = ['kind' => 'visibility', 'metric' => $metric, 'path' => $row->path, 'before' => $before, 'now' => $now];
            }
        }

        usort($found, fn ($a, $b) => ($b['before'] - $b['now']) <=> ($a['before'] - $a['now']));

        return array_slice($found, 0, self::LIMIT);
    }

    /** @return list<array<string, mixed>> */
    public function performance(): array
    {
        // One read: the table keeps at most 20 measurements per page and strategy.
        $rows = $this->db->table('surveyor_speed')
            ->whereNull('error')
            ->orderBy('url_hash')->orderBy('strategy')->orderByDesc('id')
            ->limit(50000)
            ->get(['id', 'url_hash', 'strategy', 'path', 'score', 'lcp_ms'])
            ->groupBy(fn ($r) => $r->url_hash.'|'.$r->strategy);

        $found = [];
        foreach ($rows as $series) {
            // The newest against the best of the few before it.
            $row = $series->first();
            $previous = $series->slice(1, self::LOOKBACK);

            if ($previous->isEmpty() || $row->score === null) {
                continue;
            }

            $best = (int) $previous->max('score');
            $bestLcp = $previous->whereNotNull('lcp_ms')->min('lcp_ms');

            if ($best - (int) $row->score >= self::SPEED_DROP) {
                $found[] = ['kind' => 'performance', 'metric' => 'score', 'strategy' => $row->strategy, 'path' => $row->path, 'before' => $best, 'now' => (int) $row->score];
            } elseif ($bestLcp && $row->lcp_ms !== null && $row->lcp_ms > $bestLcp * self::LCP_WORSE) {
                $found[] = ['kind' => 'performance', 'metric' => 'lcp', 'strategy' => $row->strategy, 'path' => $row->path, 'before' => (int) $bestLcp, 'now' => (int) $row->lcp_ms];
            }
        }

        return array_slice($found, 0, self::LIMIT);
    }
}
