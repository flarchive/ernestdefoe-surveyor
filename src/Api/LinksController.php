<?php

namespace Ernestdefoe\Surveyor\Api;

use Ernestdefoe\Detour\Redirect;
use Ernestdefoe\Detour\Rules\Validator as DetourValidator;
use Ernestdefoe\Surveyor\Crawl\Auditor;
use Ernestdefoe\Surveyor\Crawl\Classifier;
use Ernestdefoe\Surveyor\Site;
use Flarum\Http\Exception\RouteNotFoundException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Link recovery: internal links whose destination is not a plain working
 * page, grouped by destination, with the pages that link there. Redirects
 * are made by Detour, through Detour's own checks; Surveyor keeps no
 * redirects of its own.
 */
class LinksController extends Controller
{
    public const PER_PAGE = 50;

    /** What link recovery lists. "ok" never is. */
    public const PROBLEMS = [Classifier::BROKEN, Classifier::REDIRECT, Classifier::RESTRICTED, Classifier::CHALLENGE, Classifier::FAILED];

    protected array $actions = ['list', 'sources', 'recheck', 'update', 'redirect', 'missing'];

    public function __construct(
        protected ConnectionInterface $db,
        protected Site $site,
        protected Auditor $auditor,
    ) {
    }

    /** @return array<string, mixed> */
    protected function list(ServerRequestInterface $request): array
    {
        $class = (string) $this->query($request, 'classification', '');
        $classes = in_array($class, self::PROBLEMS, true) ? [$class] : self::PROBLEMS;

        $query = $this->db->table('surveyor_pages')
            ->whereIn('classification', $classes)
            ->where('link_ignored', (bool) $this->query($request, 'ignored', false))
            ->whereExists(fn (Builder $q) => $q->from('surveyor_links')->whereColumn('surveyor_links.target_id', 'surveyor_pages.id'));

        $total = (clone $query)->count();

        $rows = $query
            ->select('surveyor_pages.*')
            ->selectSub(fn (Builder $q) => $q->from('surveyor_links')->selectRaw('COUNT(*)')->whereColumn('surveyor_links.target_id', 'surveyor_pages.id'), 'sources')
            ->orderByDesc('sources')
            ->orderBy('surveyor_pages.id')
            ->offset($this->offset($request))
            ->limit(self::PER_PAGE)
            ->get();

        // Which of these already have a Detour redirect (one query for the page of results).
        $redirected = $this->db->table('detour_redirects')
            ->where('type', 'exact')
            ->whereIn('source', $rows->pluck('path')->all())
            ->pluck('source')
            ->flip();

        return [
            'data' => $rows->map(fn ($row) => Present::page($row, $this->site, true) + [
                'sources' => (int) $row->sources,
                'redirected' => $redirected->has($row->path),
            ])->all(),
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    protected function sources(ServerRequestInterface $request): array
    {
        $rows = $this->db->table('surveyor_links')
            ->join('surveyor_pages', 'surveyor_pages.id', '=', 'surveyor_links.source_id')
            ->where('surveyor_links.target_id', $this->id($request))
            ->orderBy('surveyor_pages.path')
            ->limit(100)
            ->get(['surveyor_pages.id', 'surveyor_pages.path', 'surveyor_pages.title']);

        return ['data' => $rows->map(fn ($r) => ['id' => (int) $r->id, 'path' => $r->path, 'url' => $this->site->url($r->path), 'title' => $r->title])->all()];
    }

    /** @return array<string, mixed> */
    protected function recheck(ServerRequestInterface $request): array
    {
        $row = $this->auditor->recheck($this->id($request));

        if (! $row) {
            throw new RouteNotFoundException();
        }

        return ['data' => Present::page($row, $this->site, true)];
    }

    /** @return array<string, mixed> */
    protected function update(ServerRequestInterface $request): array
    {
        $id = $this->id($request);
        $this->db->table('surveyor_pages')->where('id', $id)->update(['link_ignored' => (bool) ($this->body($request)['ignored'] ?? false)]);
        $row = $this->db->table('surveyor_pages')->where('id', $id)->first();

        if (! $row) {
            throw new RouteNotFoundException();
        }

        return ['data' => Present::page($row, $this->site, true)];
    }

    /**
     * Makes an exact Detour redirect, validated by Detour exactly as its own
     * form is: same refusals (home page, reserved paths, duplicates, loops,
     * other hosts), same messages.
     *
     * @return array<string, mixed>
     */
    protected function redirect(ServerRequestInterface $request): array
    {
        $body = $this->body($request);

        $columns = resolve(DetourValidator::class)->validate([
            'type' => 'exact',
            'source' => (string) ($body['source'] ?? ''),
            'target' => (string) ($body['target'] ?? ''),
            'status' => (int) ($body['status'] ?? 301),
            'mode' => ($body['mode'] ?? 'missing') === 'always' ? 'always' : 'missing',
            'external' => false,
            'note' => 'Surveyor',
        ]);

        $redirect = new Redirect();
        $redirect->position = 0;
        $redirect->forceFill($columns)->save();

        return ['data' => $redirect->toArray()];
    }

    /**
     * Detour's most-hit 404s. Detour does not record user agents, so this is
     * every visitor's 404s, bots included (unless Detour is set to ignore bots).
     *
     * @return array<string, mixed>
     */
    protected function missing(ServerRequestInterface $request): array
    {
        $rows = $this->db->table('detour_missing')
            ->where('ignored', false)
            ->orderByDesc('hits')
            ->limit(25)
            ->get(['id', 'url', 'hits', 'last_seen_at']);

        return ['data' => $rows->map(fn ($r) => ['id' => (int) $r->id, 'url' => $r->url, 'hits' => (int) $r->hits, 'lastSeenAt' => self::time($r->last_seen_at)])->all()];
    }
}
