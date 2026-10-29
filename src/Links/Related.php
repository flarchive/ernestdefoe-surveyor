<?php

namespace Ernestdefoe\Surveyor\Links;

use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\User\Guest;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Discussions related to one discussion: ones a guest can see that share its
 * tags, ranked by shared tags, shared #hashtags and shared title words.
 *
 * One query finds the candidates (discussions sharing at least one tag, with
 * how many they share); the ranking is done in PHP. The forum block caches
 * the result per discussion, so a page view costs that one query at most
 * once every six hours, and nothing at all while the block is off.
 */
class Related
{
    public const CANDIDATES = 40;
    public const TTL = 21600;

    private const STOP = ['about', 'after', 'again', 'also', 'been', 'before', 'being', 'best', 'between', 'could', 'does', 'doing', 'from', 'have', 'here', 'into', 'just', 'like', 'more', 'most', 'much', 'only', 'other', 'over', 'same', 'should', 'some', 'such', 'than', 'that', 'their', 'them', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'very', 'want', 'were', 'what', 'when', 'where', 'which', 'while', 'with', 'would', 'your'];

    public function __construct(
        protected SlugManager $slugs,
        protected Cache $cache,
    ) {
    }

    /** @return list<array{id: int, title: string, slug: string}> */
    public function cached(Discussion $discussion, int $limit): array
    {
        return $this->cache->remember(
            'ernestdefoe-surveyor.related.'.$discussion->id.'.'.$limit,
            self::TTL,
            fn () => array_map(fn ($r) => ['id' => $r['id'], 'title' => $r['title'], 'slug' => $r['slug']], $this->find($discussion, $limit))
        );
    }

    /** @return list<array{id: int, title: string, slug: string, score: int, sharedTags: int, sharedWords: list<string>}> */
    public function find(Discussion $discussion, int $limit): array
    {
        $candidates = Discussion::whereVisibleTo(new Guest())
            ->join('discussion_tag as surveyor_dt', 'surveyor_dt.discussion_id', '=', 'discussions.id')
            ->whereIn('surveyor_dt.tag_id', fn ($q) => $q->from('discussion_tag')->where('discussion_id', $discussion->id)->select('tag_id'))
            ->where('discussions.id', '!=', $discussion->id)
            ->groupBy('discussions.id', 'discussions.title', 'discussions.slug', 'discussions.last_posted_at')
            ->orderByRaw('COUNT(*) DESC')
            ->orderByDesc('discussions.last_posted_at')
            ->limit(self::CANDIDATES)
            ->get(['discussions.id', 'discussions.title', 'discussions.slug', 'discussions.last_posted_at', $discussion->getConnection()->raw('COUNT(*) as shared_tags')]);

        $mine = self::words((string) $discussion->title);
        $results = [];

        foreach ($candidates as $candidate) {
            $theirs = self::words((string) $candidate->title);
            $shared = array_values(array_intersect($mine, $theirs));
            $hashtags = count(array_filter($shared, fn ($w) => str_starts_with($w, '#')));
            $score = 3 * (int) $candidate->getAttribute('shared_tags') + 2 * $hashtags + count($shared);

            $results[] = [
                'id' => (int) $candidate->id,
                'title' => (string) $candidate->title,
                'slug' => $this->slugs->forResource(Discussion::class)->toSlug($candidate),
                'score' => $score,
                'sharedTags' => (int) $candidate->getAttribute('shared_tags'),
                'sharedWords' => $shared,
            ];
        }

        // Stable: the query's order (most shared tags, most recent) breaks ties.
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    /** @return list<string> lower-case words of four letters or more, and #hashtags */
    public static function words(string $text): array
    {
        preg_match_all('/#?[\p{L}\p{N}][\p{L}\p{N}_-]*/u', mb_strtolower($text), $m);

        return array_values(array_unique(array_filter($m[0], fn ($w) => str_starts_with($w, '#') ? mb_strlen($w) > 2 : (mb_strlen($w) >= 4 && ! in_array($w, self::STOP, true)))));
    }
}
