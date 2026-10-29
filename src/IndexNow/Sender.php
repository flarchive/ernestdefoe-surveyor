<?php

namespace Ernestdefoe\Surveyor\IndexNow;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Crawl\Eligibility;
use Ernestdefoe\Surveyor\Settings;
use Ernestdefoe\Surveyor\Site;
use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\User\Guest;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Sends the queue to IndexNow in batches.
 *
 *  - A discussion a guest can see is sent by its current address.
 *  - One that is gone, hidden or private is sent only if its address was sent
 *    before (so search engines recheck it and drop it). An address never
 *    sent is never revealed: a private discussion's title is in its slug.
 *  - Each discussion at most once an hour, however busy it is.
 *  - After a refusal or an outage, nothing is sent until the back-off ends.
 */
class Sender
{
    public const BATCH = 500;
    public const MANUAL_MAX = 100;
    public const DEBOUNCE_MINUTES = 60;
    public const LOG_ROWS = 200;
    public const BACKOFF_KEY = 'ernestdefoe-surveyor.indexnow_backoff';

    public function __construct(
        protected ConnectionInterface $db,
        protected Submitter $submitter,
        protected Settings $settings,
        protected Site $site,
        protected UrlGenerator $url,
        protected SlugManager $slugs,
        protected Eligibility $eligibility,
        protected Cache $cache,
    ) {
    }

    public function backoffUntil(): ?int
    {
        $until = (int) $this->cache->get(self::BACKOFF_KEY, 0);

        return $until > time() ? $until : null;
    }

    /** @return int addresses sent (0 when there was nothing to send, or it is not allowed now) */
    public function sendQueue(): int
    {
        if (! $this->settings->indexNowActive() || $this->backoffUntil() !== null) {
            return 0;
        }

        $rows = $this->db->table('surveyor_indexnow_queue')
            ->where('pending', true)
            ->where(fn ($q) => $q->whereNull('submitted_at')->orWhere('submitted_at', '<', Carbon::now()->subMinutes(self::DEBOUNCE_MINUTES)))
            ->orderBy('queued_at')
            ->limit(self::BATCH)
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $visible = Discussion::whereVisibleTo(new Guest())
            ->whereIn('discussions.id', $rows->pluck('discussion_id')->all())
            ->get()
            ->keyBy('id');

        $urls = [];
        $sent = [];
        $drop = [];

        foreach ($rows as $row) {
            $id = (int) $row->discussion_id;
            $discussion = $visible->get($id);
            $current = null;

            if ($discussion && $this->allowed($path = '/d/'.$this->slugs->forResource(Discussion::class)->toSlug($discussion))) {
                $current = $this->site->url($path);
                $urls[] = $current;
            }

            if ($row->url && $row->url !== $current) {
                $urls[] = $row->url;
            }

            if ($current === null && ! $row->url) {
                $drop[] = $id;
            } elseif ($current === null && $discussion) {
                $sent[$id] = null; // ruled out: its old address goes out once, then it leaves the queue
            } else {
                $sent[$id] = $current;
            }
        }

        if ($drop) {
            $this->db->table('surveyor_indexnow_queue')->whereIn('discussion_id', $drop)->delete();
        }

        $urls = array_values(array_unique($urls));

        if (! $urls) {
            return 0;
        }

        $status = $this->post($urls, 'schedule');
        $ok = $status === 200 || $status === 202;

        foreach ($sent as $id => $current) {
            $this->db->table('surveyor_indexnow_queue')->where('discussion_id', $id)->update($ok
                ? ['pending' => false, 'submitted_at' => Carbon::now(), 'url' => $current, 'last_status' => $status]
                : ['last_status' => $status ?: null]);
        }

        return $ok ? count($urls) : 0;
    }

    /**
     * Sends addresses an admin typed in.
     *
     * @param list<string> $urls
     * @return array{status: int, sent: list<string>, refused: list<string>}
     */
    public function sendManual(array $urls): array
    {
        $sent = [];
        $refused = [];

        foreach (array_slice($urls, 0, self::MANUAL_MAX) as $url) {
            $url = trim($url);
            if ($url === '') {
                continue;
            }
            $path = $this->site->path($url);
            if ($path !== null && $this->eligibility->reason($path, null, 'indexnow') === null) {
                $sent[] = $this->site->url($path);
            } else {
                $refused[] = mb_substr($url, 0, 300);
            }
        }

        $sent = array_values(array_unique($sent));

        return ['status' => $sent ? $this->post($sent, 'manual') : 0, 'sent' => $sent, 'refused' => $refused];
    }

    private function allowed(string $path): bool
    {
        return $this->eligibility->reason($path, null, 'indexnow') === null;
    }

    /** @param list<string> $urls */
    private function post(array $urls, string $origin): int
    {
        $key = (string) $this->settings->indexNowKey();

        $result = $this->submitter->submit([
            'host' => $this->site->host(),
            'key' => $key,
            'keyLocation' => $this->site->url('/'.$key.'.txt'),
            'urlList' => $urls,
        ]);

        $status = $result['status'];

        // 429 = too many requests; 403 = key not accepted (yet); anything else unexpected: a short pause.
        $pause = match (true) {
            $status === 200, $status === 202 => 0,
            $status === 403 => 6 * 3600,
            $status === 429 => 3600,
            $status === 400, $status === 422 => 0,
            default => 900,
        };
        if ($pause > 0) {
            $this->cache->put(self::BACKOFF_KEY, time() + $pause, $pause);
        }

        $this->db->table('surveyor_indexnow_log')->insert([
            'origin' => $origin,
            'url_count' => count($urls),
            'status' => $status ?: null,
            'message' => $result['message'] === null ? null : mb_substr($result['message'], 0, 255),
            'sample' => json_encode(array_slice($urls, 0, 5)),
            'created_at' => Carbon::now(),
        ]);

        $max = (int) $this->db->table('surveyor_indexnow_log')->max('id');
        $this->db->table('surveyor_indexnow_log')->where('id', '<=', $max - self::LOG_ROWS)->delete();

        return $status;
    }
}
