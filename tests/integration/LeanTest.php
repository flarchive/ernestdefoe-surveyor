<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use PHPUnit\Framework\Attributes\Test;

/**
 * Surveyor must cost a forum page view nothing: not one query. Measured on
 * the discussion list (HTML) and /api/discussions, as a guest and as an
 * admin, with 1 and with 20 discussions, with Surveyor enabled and disabled
 * (its required companions stay enabled both times, so only Surveyor differs).
 */
class LeanTest extends SurveyorTestCase
{
    private const WITHOUT = ['flarum-tags', 'fof-seo', 'fof-sitemap', 'ernestdefoe-detour'];

    private function discussions(int $count): void
    {
        $now = Carbon::now()->subDay()->toDateTimeString();
        $discussions = [];
        $posts = [];
        for ($i = 0; $i < $count; $i++) {
            $id = 100 + $i;
            $discussions[] = ['id' => $id, 'title' => "Discussion $id", 'slug' => "discussion-$id", 'created_at' => $now, 'last_posted_at' => $now, 'user_id' => 2, 'first_post_id' => $id, 'last_post_id' => $id, 'comment_count' => 1, 'last_post_number' => 1];
            $posts[] = ['id' => $id, 'discussion_id' => $id, 'created_at' => $now, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Post</p></t>', 'number' => 1];
        }
        $this->prepareDatabase([Discussion::class => $discussions, Post::class => $posts]);
    }

    /** Boots the forum again with another set of extensions (same test, same data). */
    private function boot(array $extensions): void
    {
        if ($this->app !== null) {
            $this->database()->rollBack();
        }
        $this->app = null;
        $this->server = null;
        $this->database = null;
        $this->extensions = $extensions;
        $this->app();
    }

    /** @return list<string> */
    private function queries(string $path, ?int $as): array
    {
        $db = $this->database();
        $request = $this->request('GET', $path, $as ? ['authenticatedAs' => $as] : []);
        if (! str_starts_with($path, '/api')) {
            $request = $request->withHeader('Accept', 'text/html');
        }

        // Warm up: the first request after boot compiles assets and fills caches.
        $this->send($request);

        $db->flushQueryLog();
        $db->enableQueryLog();
        $response = $this->send($request);
        $queries = array_column($db->getQueryLog(), 'query');
        $db->disableQueryLog();

        $this->assertSame(200, $response->getStatusCode(), $path);

        return $queries;
    }

    /** @return array<string, int> */
    private function measure(): array
    {
        $counts = [];
        foreach (['/', '/api/discussions'] as $path) {
            foreach ([null, 1] as $as) {
                $queries = $this->queries($path, $as);
                $this->assertSame([], array_values(array_filter($queries, fn ($q) => str_contains($q, 'surveyor_'))), $path);
                $counts[$path.' as '.($as ? 'admin' : 'guest')] = count($queries);
            }
        }

        return $counts;
    }

    #[Test]
    public function surveyor_adds_no_query_to_forum_pages_or_the_api(): void
    {
        $this->discussions(20);

        $this->boot(self::EXTENSIONS);
        $with = $this->measure();

        $this->boot(self::WITHOUT);
        // Really off this time: its routes are gone.
        $this->assertSame(404, $this->api('GET', '/summary')->getStatusCode());
        $without = $this->measure();

        if ($log = getenv('SURVEYOR_LEAN_LOG')) {
            file_put_contents($log, json_encode(['with' => $with, 'without' => $without], JSON_PRETTY_PRINT));
        }

        $this->assertSame($without, $with);
    }

    #[Test]
    public function the_cost_does_not_grow_with_the_number_of_discussions(): void
    {
        $this->discussions(1);
        $this->boot(self::EXTENSIONS);
        $one = $this->measure();

        $this->discussions(19);
        $this->boot(self::EXTENSIONS);
        $twenty = $this->measure();

        if ($log = getenv('SURVEYOR_LEAN_LOG')) {
            file_put_contents($log.'.n', json_encode(['one' => $one, 'twenty' => $twenty], JSON_PRETTY_PRINT));
        }

        $this->assertSame($one, $twenty);
    }
}
