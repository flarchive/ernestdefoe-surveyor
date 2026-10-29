<?php

namespace Ernestdefoe\Surveyor\Api;

use Ernestdefoe\Surveyor\Crawl\Classifier;
use Ernestdefoe\Surveyor\Crawl\Scorer;
use Ernestdefoe\Surveyor\Site;
use Flarum\Http\Exception\RouteNotFoundException;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

class PagesController extends Controller
{
    public const PER_PAGE = 50;

    protected array $actions = ['list', 'show', 'duplicates'];

    public function __construct(
        protected ConnectionInterface $db,
        protected Site $site,
    ) {
    }

    /** @return array<string, mixed> */
    protected function list(ServerRequestInterface $request): array
    {
        $query = $this->db->table('surveyor_pages')->whereNotNull('crawled_at');

        $q = trim((string) $this->query($request, 'q', ''));
        if ($q !== '') {
            $query->where('path', 'like', '%'.addcslashes(mb_substr($q, 0, 200), '%_\\').'%');
        }

        $class = (string) $this->query($request, 'classification', '');
        if (in_array($class, Classifier::ALL, true)) {
            $query->where('classification', $class);
        }

        $issue = (string) $this->query($request, 'issue', '');
        if (isset(Scorer::WEIGHTS[$issue])) {
            $query->where('issues', 'like', '%"'.$issue.'"%');
        }

        $total = (clone $query)->count();

        match ((string) $this->query($request, 'sort', '')) {
            'path' => $query->orderBy('path'),
            'slow' => $query->orderByDesc('response_ms'),
            'recent' => $query->orderByDesc('crawled_at'),
            default => $query->orderByRaw('CASE WHEN score IS NULL THEN 1 ELSE 0 END')->orderBy('score'),
        };

        $rows = $query->orderBy('id')->offset($this->offset($request))->limit(self::PER_PAGE)->get();

        return ['data' => $rows->map(fn ($row) => Present::page($row, $this->site))->all(), 'total' => $total];
    }

    /** @return array<string, mixed> */
    protected function show(ServerRequestInterface $request): array
    {
        $row = $this->db->table('surveyor_pages')->where('id', $this->id($request))->first();

        if (! $row) {
            throw new RouteNotFoundException();
        }

        $history = $this->db->table('surveyor_scores')
            ->join('surveyor_runs', 'surveyor_runs.id', '=', 'surveyor_scores.run_id')
            ->where('surveyor_scores.page_id', $row->id)
            ->orderByDesc('surveyor_scores.run_id')
            ->limit(24)
            ->get(['surveyor_scores.run_id', 'surveyor_scores.score', 'surveyor_scores.status_code', 'surveyor_runs.started_at']);

        return ['data' => Present::page($row, $this->site, true) + [
            'history' => $history->map(fn ($h) => [
                'runId' => (int) $h->run_id,
                'score' => $h->score === null ? null : (int) $h->score,
                'status' => $h->status_code === null ? null : (int) $h->status_code,
                'at' => self::time($h->started_at),
            ])->all(),
            'inbound' => $this->db->table('surveyor_links')->where('target_id', $row->id)->count(),
        ]];
    }

    /**
     * Pages that share a title or a description. Pages whose canonical
     * points elsewhere are left out: they say themselves that they are copies.
     *
     * @return array<string, mixed>
     */
    protected function duplicates(ServerRequestInterface $request): array
    {
        $column = $this->query($request, 'kind') === 'description' ? 'description_hash' : 'title_hash';

        $groups = $this->db->table('surveyor_pages')
            ->whereNotNull($column)
            ->where(fn ($q) => $q->whereNull('canonical_self')->orWhere('canonical_self', true))
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(50)
            ->get([$column.' as hash', $this->db->raw('COUNT(*) as total')]);

        $pages = $this->db->table('surveyor_pages')
            ->whereIn($column, $groups->pluck('hash')->all())
            ->where(fn ($q) => $q->whereNull('canonical_self')->orWhere('canonical_self', true))
            ->orderBy('path')
            ->limit(1000)
            ->get(['id', 'path', 'title', 'description', $column.' as hash'])
            ->groupBy('hash');

        return ['data' => $groups->map(function ($group) use ($pages, $column) {
            $members = $pages->get($group->hash, collect());
            $first = $members->first();

            return [
                'text' => $first ? ($column === 'title_hash' ? $first->title : $first->description) : null,
                'total' => (int) $group->total,
                'pages' => $members->take(10)->map(fn ($p) => ['id' => (int) $p->id, 'path' => $p->path, 'url' => $this->site->url($p->path)])->values()->all(),
            ];
        })->all()];
    }
}
