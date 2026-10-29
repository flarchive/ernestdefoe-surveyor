<?php

namespace Ernestdefoe\Surveyor\Links;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * The forum's internal links as the last audits saw them: pages no other
 * page links to (orphans), pages with a single link in, and hubs.
 */
class Graph
{
    public const LIMIT = 50;

    public function __construct(
        protected ConnectionInterface $db,
    ) {
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function summary(): array
    {
        $inbound = fn (Builder $q) => $q->from('surveyor_links')->selectRaw('COUNT(*)')->whereColumn('surveyor_links.target_id', 'surveyor_pages.id');

        $pages = fn () => $this->db->table('surveyor_pages')->where('classification', 'ok')->whereNotNull('score')->where('found_via', '!=', 'home');

        $orphans = $pages()
            ->whereNotExists(fn (Builder $q) => $q->from('surveyor_links')->whereColumn('surveyor_links.target_id', 'surveyor_pages.id'))
            ->orderBy('path')->limit(self::LIMIT)
            ->get(['id', 'path', 'title']);

        $weak = $pages()
            ->select('id', 'path', 'title')
            ->selectSub($inbound, 'inbound')
            ->where($inbound, '=', 1)
            ->orderBy('path')->limit(self::LIMIT)
            ->get();

        $linked = $this->db->table('surveyor_pages')->where('classification', 'ok')
            ->select('id', 'path', 'title')->selectSub($inbound, 'inbound')
            ->orderByDesc('inbound')->limit(20)->get();

        $linking = $this->db->table('surveyor_pages')->where('classification', 'ok')->whereNotNull('links_out')
            ->orderByDesc('links_out')->limit(20)->get(['id', 'path', 'title', 'links_out']);

        $row = fn ($r) => ['id' => (int) $r->id, 'path' => $r->path, 'title' => $r->title, 'inbound' => isset($r->inbound) ? (int) $r->inbound : null, 'linksOut' => isset($r->links_out) ? (int) $r->links_out : null];

        return [
            'orphans' => $orphans->map($row)->all(),
            'weak' => $weak->map($row)->all(),
            'mostLinked' => $linked->map($row)->all(),
            'mostLinking' => $linking->map($row)->all(),
        ];
    }
}
