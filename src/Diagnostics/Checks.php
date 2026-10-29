<?php

namespace Ernestdefoe\Surveyor\Diagnostics;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Crawl\Analyzer;
use Ernestdefoe\Surveyor\Crawl\Robots;
use Ernestdefoe\Surveyor\Google\SearchSync;
use Ernestdefoe\Surveyor\Http\ApiLog;
use Ernestdefoe\Surveyor\Http\Fetcher;
use Ernestdefoe\Surveyor\Security\Secrets;
use Ernestdefoe\Surveyor\Http\Page;
use Ernestdefoe\Surveyor\Settings;
use Ernestdefoe\Surveyor\Site;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ApplicationInfoProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Throwable;
use XMLReader;

/**
 * Passed / warning / info checks. Each comes back as an id, a level and a few
 * plain values; the admin words them from the locale file.
 */
class Checks
{
    public const TABLES = [
        'surveyor_runs', 'surveyor_pages', 'surveyor_links', 'surveyor_scores', 'surveyor_rules', 'surveyor_indexnow_queue', 'surveyor_indexnow_log',
        'surveyor_secrets', 'surveyor_api_log', 'surveyor_search_pages', 'surveyor_search_queries', 'surveyor_search_days', 'surveyor_search_history',
        'surveyor_inspections', 'surveyor_speed', 'surveyor_speed_queue', 'surveyor_ai_descriptions',
    ];

    /** Extensions that do a job Surveyor or its companions already do. */
    public const CONFLICTS = ['blenderrus-indexnow' => 'indexnow', 'v17development-seo' => 'seo'];

    public const TICK_KEY = 'ernestdefoe-surveyor.tick';

    /** Short, so every check fits in one web request even when the forum does not answer. */
    private const TIMEOUT = 5;

    public function __construct(
        protected Fetcher $fetcher,
        protected Site $site,
        protected Settings $settings,
        protected ExtensionManager $extensions,
        protected ConnectionInterface $db,
        protected Cache $cache,
        protected ApplicationInfoProvider $info,
        protected Analyzer $analyzer,
        protected Secrets $secrets,
        protected SearchSync $sync,
        protected ApiLog $log,
    ) {
    }

    /** @return list<array{id: string, level: string, params: array<string, string|int>}> */
    public function run(): array
    {
        $checks = [];

        foreach (['fof-seo', 'fof-sitemap', 'ernestdefoe-detour'] as $id) {
            $ext = $this->extensions->getExtension($id);
            $checks[] = $this->extensions->isEnabled($id)
                ? $this->check('extension', 'pass', ['name' => $id, 'version' => (string) $ext?->getVersion()])
                : $this->check('extension', 'warning', ['name' => $id, 'version' => (string) $ext?->getVersion()]);
        }

        $conflicts = [];
        foreach (self::CONFLICTS as $id => $_) {
            if ($this->extensions->isEnabled($id)) {
                $conflicts[] = $id;
            }
        }
        $checks[] = $conflicts
            ? $this->check('conflicts', 'warning', ['names' => implode(', ', $conflicts)])
            : $this->check('conflicts', 'pass');

        $local = in_array($this->site->host(), ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($this->site->host(), '.test') || str_ends_with($this->site->host(), '.localhost');
        $checks[] = $this->check('https', $this->site->isHttps() ? 'pass' : ($local ? 'info' : 'warning'), ['url' => $this->site->base()]);

        $home = $this->fetcher->get($this->site->url('/'), Page::MAX_BYTES, self::TIMEOUT);
        if ($home->status === 200) {
            $checks[] = $this->check('self_fetch', 'pass', ['ms' => $home->ms]);
            $canonical = $this->analyzer->analyze($home)['canonical'];
            $checks[] = match (true) {
                $canonical === null => $this->check('canonical_home', 'warning', ['canonical' => '']),
                Analyzer::same($canonical, $this->site->base()) || Analyzer::same($canonical, $this->site->url('/')) => $this->check('canonical_home', 'pass'),
                default => $this->check('canonical_home', 'warning', ['canonical' => mb_substr($canonical, 0, 300)]),
            };
        } else {
            $checks[] = $this->check('self_fetch', 'warning', ['status' => $home->status, 'error' => (string) $home->error]);
        }

        // When the server cannot reach the forum at all, the other fetches would only time out too.
        $reachable = $home->status > 0;
        $checks[] = $reachable ? $this->sitemap() : $this->check('sitemap', 'warning', ['url' => $this->site->url('/sitemap.xml'), 'status' => 0, 'error' => 'unreachable']);
        $checks[] = $reachable ? $this->robots() : $this->check('robots', 'warning', ['url' => $this->site->robotsUrl(), 'status' => 0]);
        $checks[] = $this->scheduler();
        $checks[] = $this->check('queue', 'info', ['driver' => $this->queueDriver()]);
        $checks[] = $reachable || ! $this->settings->indexNowActive() ? $this->indexNow() : $this->check('indexnow', 'warning', ['url' => $this->site->url('/'.$this->settings->indexNowKey().'.txt'), 'status' => 0]);

        $checks = array_merge($checks, $this->services());

        // One listing, not one query per table. Names come back with the table prefix.
        $prefix = $this->db->getTablePrefix();
        $present = array_column($this->db->getSchemaBuilder()->getTables(), 'name');
        $missing = array_values(array_filter(self::TABLES, fn ($t) => ! in_array($prefix.$t, $present, true)));
        $checks[] = $missing ? $this->check('tables', 'warning', ['tables' => implode(', ', $missing)]) : $this->check('tables', 'pass');

        return $checks;
    }

    /** @return array{id: string, level: string, params: array<string, string|int>} */
    private function sitemap(): array
    {
        $url = $this->site->url('/sitemap.xml');
        $page = $this->fetcher->get($url, 20_000_000, self::TIMEOUT);

        if ($page->status !== 200) {
            return $this->check('sitemap', 'warning', ['url' => $url, 'status' => $page->status, 'error' => (string) $page->error]);
        }

        $reader = new XMLReader();
        $locs = 0;
        $offHost = 0;
        $root = null;

        if (@$reader->XML($page->body, null, LIBXML_NONET | LIBXML_COMPACT)) {
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                $root ??= $reader->localName;
                if ($reader->localName === 'loc') {
                    $locs++;
                    if (! $this->site->owns(trim($reader->readString()))) {
                        $offHost++;
                    }
                }
            }
            $reader->close();
        }

        if (! in_array($root, ['sitemapindex', 'urlset'], true)) {
            return $this->check('sitemap', 'warning', ['url' => $url, 'status' => 200, 'error' => 'invalid']);
        }

        if ($offHost > 0) {
            return $this->check('sitemap_hosts', 'warning', ['count' => $offHost]);
        }

        return $this->check('sitemap', 'pass', ['url' => $url, 'count' => $locs]);
    }

    /** @return array{id: string, level: string, params: array<string, string|int>} */
    private function robots(): array
    {
        $url = $this->site->robotsUrl();
        $page = $this->fetcher->get($url, 500_000, self::TIMEOUT);

        if ($page->status !== 200) {
            return $this->check('robots', 'warning', ['url' => $url, 'status' => $page->status]);
        }

        $robots = Robots::parse($page->body);

        if (! $robots->allows($this->site->basePath().'/')) {
            return $this->check('robots_blocks', 'warning', ['url' => $url]);
        }

        $sitemap = $this->site->url('/sitemap.xml');
        foreach ($robots->sitemaps as $listed) {
            if (rtrim($listed, '/') === $sitemap) {
                return $this->check('robots', 'pass', ['url' => $url]);
            }
        }

        return $this->check('robots_sitemap', 'warning', ['url' => $url, 'sitemap' => $sitemap]);
    }

    /** @return array{id: string, level: string, params: array<string, string|int>} */
    private function scheduler(): array
    {
        $last = $this->cache->get('flarum:schedule:last_run');
        $tick = (int) $this->cache->get(self::TICK_KEY, 0);

        if ($last && Carbon::parse($last)->gt(Carbon::now()->subMinutes(5))) {
            return $this->check('scheduler', 'pass', ['tick' => $tick ? Carbon::createFromTimestamp($tick)->toIso8601String() : '']);
        }

        return $this->check('scheduler', 'warning', ['last' => $last ? Carbon::parse($last)->toIso8601String() : '']);
    }

    /** @return array{id: string, level: string, params: array<string, string|int>} */
    private function indexNow(): array
    {
        if (! $this->settings->indexNowActive()) {
            return $this->check('indexnow', 'info');
        }

        $key = (string) $this->settings->indexNowKey();
        $url = $this->site->url('/'.$key.'.txt');
        $page = $this->fetcher->get($url, 10_000, self::TIMEOUT);

        return $page->status === 200 && trim($page->body) === $key
            ? $this->check('indexnow', 'pass', ['url' => $url])
            : $this->check('indexnow', 'warning', ['url' => $url, 'status' => $page->status]);
    }

    /**
     * Search Console, PageSpeed and the AI provider, from what Surveyor
     * already knows (its log and pauses): no extra calls to them.
     *
     * @return list<array{id: string, level: string, params: array<string, string|int>}>
     */
    private function services(): array
    {
        $checks = [];
        $last = fn (string $service) => $this->db->table('surveyor_api_log')->where('service', $service)->orderByDesc('id')->first(['status', 'message']);

        if (! $this->secrets->has(Secrets::GOOGLE)) {
            $checks[] = $this->check('google', 'info');
        } elseif (! $this->sync->property()) {
            $checks[] = $this->check('google_property', 'warning');
        } elseif ($this->log->pausedUntil('google') !== null) {
            $checks[] = $this->check('google', 'warning', ['error' => (string) ($last('google')->message ?? '')]);
        } else {
            $checks[] = $this->check('google', 'pass', ['email' => (string) $this->secrets->label(Secrets::GOOGLE)]);
        }

        $checks[] = $this->log->pausedUntil('pagespeed') !== null
            ? $this->check('pagespeed', 'warning', ['error' => (string) ($last('pagespeed')->message ?? '')])
            : $this->check('pagespeed', $this->secrets->has(Secrets::PAGESPEED) ? 'pass' : 'info');

        if (! $this->settings->on('ai_enabled')) {
            $checks[] = $this->check('ai', 'info');
        } elseif (! $this->secrets->has(Secrets::ANTHROPIC) || $this->log->pausedUntil('ai') !== null) {
            $checks[] = $this->check('ai', 'warning', ['error' => (string) ($last('ai')->message ?? '')]);
        } else {
            $checks[] = $this->check('ai', 'pass');
        }

        // The key that encrypts the stored keys: present, and not readable by everyone.
        $file = $this->secrets->keyFile();
        $checks[] = ! is_file($file)
            ? $this->check('secrets_key', 'info', ['file' => $file])
            : ((fileperms($file) & 0o004) ? $this->check('secrets_key', 'warning', ['file' => $file]) : $this->check('secrets_key', 'pass', ['file' => $file]));

        $checks[] = $this->check('related', $this->settings->on('related_enabled') ? 'pass' : 'info');

        return $checks;
    }

    private function queueDriver(): string
    {
        try {
            return $this->info->identifyQueueDriver();
        } catch (Throwable) {
            return '?';
        }
    }

    /**
     * @param array<string, string|int> $params
     * @return array{id: string, level: string, params: array<string, string|int>}
     */
    private function check(string $id, string $level, array $params = []): array
    {
        return ['id' => $id, 'level' => $level, 'params' => $params];
    }
}
