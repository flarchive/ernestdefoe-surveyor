<?php

namespace Ernestdefoe\Surveyor\Crawl;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Http\Fetcher;
use Ernestdefoe\Surveyor\Http\Page;
use Ernestdefoe\Surveyor\Settings;
use Ernestdefoe\Surveyor\Site;
use Illuminate\Database\ConnectionInterface;
use XMLReader;

/**
 * The site audit. A run moves through three phases, one fetch at a time:
 *
 *   robots  — read robots.txt (once), queue the home page
 *   sitemap — read FoF Sitemap's index and each sitemap it lists
 *   crawl   — fetch each queued page as a guest, score it, record its links
 *
 * Everything a run needs to carry on lives in its row, so a run that was
 * stopped half way (a deploy, a timeout) resumes on the next tick. Each
 * fetch is one "unit"; the caller decides how many units a tick may spend
 * and how far apart they are, which is what keeps the crawler polite.
 */
class Auditor
{
    /** Sitemap files read per run, and addresses taken from them. */
    public const MAX_SITEMAPS = 50;
    public const MAX_SITEMAP_URLS = 50_000;
    public const SITEMAP_BYTES = 20_000_000;

    /** A run's lock is held this long; a crashed holder frees it by expiring. */
    private const LOCK_SECONDS = 120;

    private const CHUNK = 500;

    /** The run being worked on during work(), mirrored in memory. */
    private ?object $run = null;

    public function __construct(
        protected ConnectionInterface $db,
        protected Fetcher $fetcher,
        protected Site $site,
        protected Settings $settings,
        protected Eligibility $eligibility,
        protected Analyzer $analyzer,
        protected Classifier $classifier,
    ) {
    }

    public function current(): ?object
    {
        return $this->db->table('surveyor_runs')->where('status', 'running')->orderByDesc('id')->first();
    }

    public function latest(): ?object
    {
        return $this->db->table('surveyor_runs')->orderByDesc('id')->first();
    }

    /** Is a scheduled audit due? */
    public function due(): bool
    {
        $days = $this->settings->frequencyDays();

        if ($days === 0 || $this->current()) {
            return false;
        }

        $last = $this->db->table('surveyor_runs')->orderByDesc('id')->value('started_at');

        return $last === null || Carbon::parse($last)->lt(Carbon::now()->subDays($days)->addMinutes(30));
    }

    /** Starts an audit, or returns the one already going. */
    public function start(string $origin): int
    {
        if ($run = $this->current()) {
            return (int) $run->id;
        }

        return (int) $this->db->table('surveyor_runs')->insertGetId([
            'status' => 'running',
            'phase' => 'robots',
            'origin' => $origin,
            'state' => json_encode([]),
            'started_at' => Carbon::now(),
        ]);
    }

    public function stop(): void
    {
        if (! $run = $this->current()) {
            return;
        }

        $this->db->table('surveyor_pages')->where('queued_run', $run->id)->update(['queued_run' => null]);
        $this->db->table('surveyor_runs')->where('id', $run->id)->update([
            'status' => 'stopped',
            'finished_at' => Carbon::now(),
            'state' => null,
            'locked_until' => null,
        ]);
    }

    /**
     * Spends up to $units fetches on the running audit, $spacing seconds
     * apart, stopping before $deadline (a unix time).
     *
     * @return int|null fetches made, or null when another process holds the run
     */
    public function work(int $units, float $spacing, float $deadline): ?int
    {
        if (! $run = $this->current()) {
            return 0;
        }

        $now = Carbon::now();
        $locked = $this->db->table('surveyor_runs')
            ->where('id', $run->id)
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', $now))
            ->update(['locked_until' => $now->copy()->addSeconds(self::LOCK_SECONDS)]);

        if ($locked !== 1) {
            return null;
        }

        $done = 0;
        // Held in memory for the whole tick and kept in step with every write,
        // so the run is read once per tick, not once per fetch.
        $this->run = $run;

        try {
            // A fetch only starts when it can finish (time out, at worst) before the deadline.
            $timeout = $this->settings->timeout();
            while ($done < $units && ($done === 0 || microtime(true) + $timeout < $deadline)) {
                $slot = microtime(true);

                $fetched = match ($this->run->phase) {
                    'robots' => $this->robots($this->run),
                    'sitemap' => $this->sitemap($this->run),
                    default => $this->crawl($this->run),
                };

                if (! $fetched) {
                    break; // finished, or stopped from elsewhere
                }

                $done++;

                $wait = $slot + $spacing - microtime(true);
                if ($done < $units && $wait > 0 && microtime(true) + $wait < $deadline) {
                    usleep((int) ($wait * 1e6));
                }
            }
        } finally {
            $this->db->table('surveyor_runs')->where('id', $run->id)->update(['locked_until' => null]);
            $this->run = null;
        }

        return $done;
    }

    /** Fetches one address now, outside any run's queue (the admin's "Recheck"). */
    public function recheck(int $pageId): ?object
    {
        $row = $this->db->table('surveyor_pages')->where('id', $pageId)->first();

        if (! $row) {
            return null;
        }

        $latest = $this->latest();
        $this->process($row, $latest ? (int) $latest->id : 0, null, false);

        return $this->db->table('surveyor_pages')->where('id', $pageId)->first();
    }

    // ── Phases ────────────────────────────────────────────────────────────

    private function robots(object $run): bool
    {
        $page = $this->fetcher->get($this->site->robotsUrl(), 500_000);
        $robots = $page->status === 200 && ! $page->failed() ? Robots::parse($page->body) : new Robots();

        $sitemaps = [$this->site->url('/sitemap.xml')];
        foreach ($robots->sitemaps as $url) {
            if ($this->site->owns($url)) {
                $sitemaps[] = $url;
            }
        }

        $this->state($run, [
            'robots' => $robots->toArray(),
            'robots_status' => $page->status,
            'sitemaps' => array_values(array_unique($sitemaps)),
            'sitemaps_read' => 0,
            'sitemap_urls' => 0,
        ], 'sitemap');

        $this->upsert(['/'], (int) $run->id, 'home');

        return true;
    }

    private function sitemap(object $run): bool
    {
        $state = $this->decode($run);
        $next = array_shift($state['sitemaps']);

        if ($next === null || $state['sitemaps_read'] >= self::MAX_SITEMAPS || $state['sitemap_urls'] >= self::MAX_SITEMAP_URLS) {
            $this->queue($run);
            $this->state($run, ['sitemaps' => []] + $state, 'crawl');

            return $this->crawl($run);
        }

        $page = $this->fetcher->get($next, self::SITEMAP_BYTES);
        $state['sitemaps_read']++;

        if ($page->status === 200 && ! $page->failed()) {
            [$children, $urls] = $this->parseSitemap($page->body, self::MAX_SITEMAP_URLS - $state['sitemap_urls']);

            foreach ($children as $child) {
                if ($this->site->owns($child) && ! in_array($child, $state['sitemaps'], true)) {
                    $state['sitemaps'][] = $child;
                }
            }

            $robots = Robots::fromArray($state['robots'] ?? null);
            $paths = [];
            foreach ($urls as $url) {
                if (($path = $this->eligibility->path($url, $robots)) !== null) {
                    $paths[$path] = true;
                }
            }

            $state['sitemap_urls'] += count($paths);
            $this->upsert(array_keys($paths), (int) $run->id, 'sitemap');
        } else {
            $state['sitemap_errors'][] = ['url' => $next, 'status' => $page->status, 'error' => $page->error];
        }

        $this->state($run, $state);

        return true;
    }

    private function crawl(object $run): bool
    {
        $row = $this->db->table('surveyor_pages')->where('queued_run', $run->id)->orderBy('id')->first();

        if (! $row) {
            $this->finish($run);

            return false;
        }

        $robots = Robots::fromArray($this->decode($run)['robots'] ?? null);
        $classification = $this->process($row, (int) $run->id, $robots, true);

        $failed = in_array($classification, [Classifier::FAILED, Classifier::CHALLENGE], true);
        $this->db->table('surveyor_runs')->where('id', $run->id)->update([
            'pages_done' => $this->db->raw('pages_done + 1'),
            'pages_failed' => $this->db->raw('pages_failed + '.($failed ? 1 : 0)),
        ]);

        return true;
    }

    /** Fetches one page, records what it is and what it links to. */
    private function process(object $row, int $runId, ?Robots $robots, bool $follow): string
    {
        $url = $this->site->url($row->path);
        $page = $this->fetcher->get($url);
        $classification = $this->classifier->classify($page, $row->path);

        $columns = [
            'queued_run' => null,
            'last_seen_run' => $runId ?: $row->last_seen_run,
            'crawled_at' => Carbon::now(),
            'classification' => $classification,
            'status_code' => $page->status ?: null,
            'final_url' => $page->finalUrl !== '' ? substr($page->finalUrl, 0, 1000) : null,
            'error' => $page->error,
            'response_ms' => $page->ms,
            'bytes' => $page->bytes ?: null,
        ] + self::blank();

        $links = [];

        if ($page->status === 200 && $page->isHtml() && ! $page->failed()) {
            $a = $this->analyzer->analyze($page);
            [$score, $issues] = Scorer::score($a, $page->ms);
            $links = $a['links'];

            $columns = array_merge($columns, [
                'title' => $a['title'] === null ? null : mb_substr($a['title'], 0, 500),
                'title_length' => $a['title_length'],
                'title_hash' => $a['title'] === null ? null : sha1(mb_strtolower($a['title'])),
                'description' => $a['description'] === null ? null : mb_substr($a['description'], 0, 500),
                'description_length' => $a['description_length'],
                'description_hash' => $a['description'] ? sha1(mb_strtolower($a['description'])) : null,
                'canonical' => $a['canonical'] === null ? null : substr($a['canonical'], 0, 1000),
                'canonical_self' => $a['canonical_self'],
                'noindex' => $a['noindex'],
                'h1_count' => min($a['h1_count'], 65535),
                'word_count' => $a['word_count'],
                'links_out' => count($a['links']),
                'images_no_alt' => min($a['images_no_alt'], 65535),
                'structured_data' => $a['structured_data'],
                'score' => $score,
                'issues' => json_encode($issues),
            ]);
        }

        $this->db->table('surveyor_pages')->where('id', $row->id)->update($columns);

        if ($runId > 0 && $follow) {
            $this->db->table('surveyor_scores')->insertOrIgnore([
                'run_id' => $runId,
                'page_id' => $row->id,
                'score' => $columns['score'],
                'status_code' => $columns['status_code'],
            ]);
        }

        $this->links((int) $row->id, $links, $runId, $robots, $follow);

        return $classification;
    }

    /** @param list<string> $urls absolute addresses on the forum */
    private function links(int $sourceId, array $urls, int $runId, ?Robots $robots, bool $follow): void
    {
        $this->db->table('surveyor_links')->where('source_id', $sourceId)->delete();

        $paths = [];
        foreach ($urls as $url) {
            if (($path = $this->eligibility->path($url, $robots)) !== null) {
                $paths[$path] = true;
            }
        }

        if (! $paths) {
            return;
        }

        $targets = $this->upsert(array_keys($paths), $runId, 'link');

        $rows = [];
        foreach ($targets as $target) {
            if ((int) $target->id !== $sourceId) {
                $rows[] = ['source_id' => $sourceId, 'target_id' => (int) $target->id];
            }
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->db->table('surveyor_links')->insertOrIgnore($chunk);
        }

        if (! $follow || $runId === 0) {
            return;
        }

        // Pages this run has not fetched yet join its queue while there is room.
        $run = $this->run;
        if (! $run || (int) $run->id !== $runId) {
            return;
        }

        $room = $this->settings->maxPages() - (int) $run->pages_queued;

        if ($room <= 0) {
            return;
        }

        $started = Carbon::parse($run->started_at);
        $new = [];
        foreach ($targets as $target) {
            if ($target->queued_run === null && ($target->crawled_at === null || Carbon::parse($target->crawled_at)->lt($started))) {
                $new[] = (int) $target->id;
                if (count($new) >= $room) {
                    break;
                }
            }
        }

        if ($new) {
            $this->db->table('surveyor_pages')->whereIn('id', $new)->update(['queued_run' => $runId]);
            $this->db->table('surveyor_runs')->where('id', $runId)->increment('pages_queued', count($new));
            $run->pages_queued = (int) $run->pages_queued + count($new);
        }
    }

    /**
     * Makes sure a row exists for each path and marks it seen in this run.
     *
     * @param list<string> $paths
     * @return list<object> id, url_hash, queued_run, crawled_at of each
     */
    private function upsert(array $paths, int $runId, string $via): array
    {
        $found = [];

        foreach (array_chunk($paths, self::CHUNK) as $chunk) {
            $rows = [];
            $hashes = [];
            foreach ($chunk as $path) {
                $hash = sha1($path);
                $hashes[] = $hash;
                $rows[] = ['url_hash' => $hash, 'path' => $path, 'found_via' => $via, 'last_seen_run' => $runId ?: null];
            }

            $this->db->table('surveyor_pages')->insertOrIgnore($rows);

            if ($runId > 0) {
                $this->db->table('surveyor_pages')->whereIn('url_hash', $hashes)->update(['last_seen_run' => $runId]);
            }

            if ($via !== 'sitemap') {
                foreach ($this->db->table('surveyor_pages')->whereIn('url_hash', $hashes)->get(['id', 'url_hash', 'queued_run', 'crawled_at']) as $row) {
                    $found[] = $row;
                }
            }
        }

        return $found;
    }

    /** The home page first, then pages never audited, then those audited longest ago. */
    private function queue(object $run): void
    {
        if (! $this->db->table('surveyor_runs')->where('id', $run->id)->where('status', 'running')->exists()) {
            return; // stopped while the sitemap was being read
        }

        $ids = $this->db->table('surveyor_pages')
            ->where('last_seen_run', $run->id)
            ->orderByRaw("CASE WHEN found_via = 'home' THEN 0 WHEN crawled_at IS NULL THEN 1 ELSE 2 END")
            ->orderBy('crawled_at')
            ->orderBy('id')
            ->limit($this->settings->maxPages())
            ->pluck('id')
            ->all();

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->db->table('surveyor_pages')->whereIn('id', $chunk)->update(['queued_run' => $run->id]);
        }

        $this->db->table('surveyor_runs')->where('id', $run->id)->update(['pages_queued' => count($ids)]);
        $run->pages_queued = count($ids);
    }

    private function finish(object $run): void
    {
        $avg = $this->db->table('surveyor_scores')->where('run_id', $run->id)->whereNotNull('score')->avg('score');
        $state = $this->decode($run);

        $finished = $this->db->table('surveyor_runs')->where('id', $run->id)->where('status', 'running')->update([
            'status' => 'done',
            'phase' => 'done',
            'avg_score' => $avg === null ? null : (int) round((float) $avg),
            'finished_at' => Carbon::now(),
            // Kept for Diagnostics; the queue itself is gone.
            'state' => json_encode(array_intersect_key($state, array_flip(['robots_status', 'sitemaps_read', 'sitemap_urls', 'sitemap_errors']))),
        ]);

        // Stopped from the admin while this tick was working: nothing to finish.
        if ($finished === 1) {
            $this->prune((int) $run->id);
        }
    }

    /** Keeps the newest runs' history, and forgets pages nobody has linked to or listed for three runs. */
    private function prune(int $runId): void
    {
        $old = $this->db->table('surveyor_runs')->orderByDesc('id')->offset($this->settings->keepRuns())->limit(1000)->pluck('id')->all();

        foreach (array_chunk($old, self::CHUNK) as $chunk) {
            $this->db->table('surveyor_scores')->whereIn('run_id', $chunk)->delete();
            $this->db->table('surveyor_runs')->whereIn('id', $chunk)->delete();
        }

        $keepFrom = $this->db->table('surveyor_runs')->where('status', 'done')->orderByDesc('id')->offset(2)->limit(1)->value('id');

        if ($keepFrom === null) {
            return;
        }

        do {
            $gone = $this->db->table('surveyor_pages')
                ->where(fn ($q) => $q->whereNull('last_seen_run')->orWhere('last_seen_run', '<', $keepFrom))
                ->whereNull('queued_run')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($gone) {
                $this->db->table('surveyor_links')->whereIn('source_id', $gone)->delete();
                $this->db->table('surveyor_links')->whereIn('target_id', $gone)->delete();
                $this->db->table('surveyor_scores')->whereIn('page_id', $gone)->delete();
                $this->db->table('surveyor_pages')->whereIn('id', $gone)->delete();
            }
        } while (count($gone) === self::CHUNK);
    }

    /**
     * @return array{0: list<string>, 1: list<string>} child sitemaps, page addresses
     */
    private function parseSitemap(string $xml, int $limit): array
    {
        $children = [];
        $urls = [];
        $reader = new XMLReader();

        if (! @$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            return [[], []];
        }

        $parent = null;
        while (@$reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            $name = $reader->localName;
            if ($name === 'sitemap' || $name === 'url') {
                $parent = $name;
            } elseif ($name === 'loc') {
                $loc = trim($reader->readString());
                if ($parent === 'sitemap') {
                    $children[] = $loc;
                } elseif (count($urls) < $limit) {
                    $urls[] = $loc;
                } else {
                    break;
                }
            }
        }

        $reader->close();

        return [$children, $urls];
    }

    /** @return array<string, mixed> */
    private function decode(object $run): array
    {
        $state = json_decode((string) ($run->state ?? ''), true);

        return (is_array($state) ? $state : []) + ['sitemaps' => [], 'sitemaps_read' => 0, 'sitemap_urls' => 0];
    }

    /** @param array<string, mixed> $state */
    private function state(object $run, array $state, ?string $phase = null): void
    {
        $columns = ['state' => json_encode($state)];
        if ($phase !== null) {
            $columns['phase'] = $phase;
        }

        $this->db->table('surveyor_runs')->where('id', $run->id)->where('status', 'running')->update($columns);

        $run->state = $columns['state'];
        $run->phase = $phase ?? $run->phase;
    }

    /** @return array<string, null> the audit columns, emptied for a page that is not HTML */
    private static function blank(): array
    {
        return array_fill_keys([
            'title', 'title_length', 'title_hash', 'description', 'description_length', 'description_hash', 'canonical',
            'canonical_self', 'noindex', 'h1_count', 'word_count', 'links_out', 'images_no_alt',
            'structured_data', 'score', 'issues',
        ], null);
    }
}
