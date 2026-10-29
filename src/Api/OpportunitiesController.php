<?php

namespace Ernestdefoe\Surveyor\Api;

use Ernestdefoe\Surveyor\Google\Opportunities;
use Ernestdefoe\Surveyor\Site;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

class OpportunitiesController extends Controller
{
    public const LIMIT = 100;

    protected array $actions = ['list'];

    public function __construct(
        protected ConnectionInterface $db,
        protected Site $site,
    ) {
    }

    /** @return array<string, mixed> */
    protected function list(ServerRequestInterface $request): array
    {
        $type = (string) $this->query($request, 'type', '');
        $priority = (string) $this->query($request, 'priority', '');

        // Only pages that can qualify for any kind (bounded either way).
        $rows = $this->db->table('surveyor_search_pages')
            ->where(fn ($q) => $q->where('impressions', '>=', Opportunities::MIN_IMPRESSIONS_STRIKING)->orWhere('prev_clicks', '>=', Opportunities::MIN_PREV_CLICKS))
            ->orderByDesc('impressions')
            ->limit(5000)
            ->get();

        $found = [];
        foreach ($rows as $row) {
            foreach (Opportunities::find($row) as $o) {
                if (($type === '' || $o['type'] === $type) && ($priority === '' || $o['priority'] === $priority)) {
                    $found[] = $o + ['row' => $row];
                }
            }
        }

        usort($found, fn ($a, $b) => $b['potential'] <=> $a['potential']);
        $counts = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($found as $o) {
            $counts[$o['priority']]++;
        }
        $found = array_slice($found, 0, self::LIMIT);

        // Top queries and the audit's view of each page: two queries for the whole list.
        $hashes = array_values(array_unique(array_map(fn ($o) => $o['row']->url_hash, $found)));
        $queries = $this->db->table('surveyor_search_queries')->whereIn('url_hash', $hashes)->orderByDesc('impressions')->get()->groupBy('url_hash');
        $audit = $this->db->table('surveyor_pages')->whereIn('url_hash', $hashes)->get(['url_hash', 'id', 'score', 'issues'])->keyBy('url_hash');

        return [
            'counts' => $counts,
            'data' => array_map(function ($o) use ($queries, $audit) {
                $page = $audit->get($o['row']->url_hash);

                return [
                    'type' => $o['type'],
                    'priority' => $o['priority'],
                    'potential' => $o['potential'],
                    'page' => SearchController::page($o['row'], $this->site),
                    'queries' => $queries->get($o['row']->url_hash, collect())->take(5)->map(fn ($q) => SearchController::metrics($q) + ['query' => $q->query])->values()->all(),
                    'auditId' => $page ? (int) $page->id : null,
                    'auditScore' => $page && $page->score !== null ? (int) $page->score : null,
                    'auditIssues' => $page ? (json_decode((string) $page->issues, true) ?: []) : [],
                ];
            }, $found),
        ];
    }
}
