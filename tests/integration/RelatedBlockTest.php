<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Ernestdefoe\Surveyor\Links\RelatedBlock;
use PHPUnit\Framework\Attributes\Test;

/**
 * The related-discussions block: absent (and free) while off; while on, one
 * cached query per discussion, and none once cached. extend.php applies
 * RelatedBlock only while the setting is on; here it is applied directly.
 */
class RelatedBlockTest extends SurveyorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $now = \Carbon\Carbon::now()->subDay()->toDateTimeString();
        $this->prepareDatabase([
            \Flarum\Discussion\Discussion::class => [
                ['id' => 20, 'title' => 'Climbing roses', 'slug' => 'climbing-roses', 'created_at' => $now, 'last_posted_at' => $now, 'user_id' => 2, 'first_post_id' => 20, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            \Flarum\Post\Post::class => [
                ['id' => 20, 'discussion_id' => 20, 'created_at' => $now, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Roses</p></t>', 'number' => 1],
            ],
            'tags' => [['id' => 1, 'name' => 'Roses', 'slug' => 'roses', 'position' => 0]],
            'discussion_tag' => [['discussion_id' => 1, 'tag_id' => 1], ['discussion_id' => 20, 'tag_id' => 1]],
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} */
    private function show(int $id): array
    {
        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();
        $response = $this->send($this->request('GET', "/api/discussions/$id"));
        $queries = array_column($db->getQueryLog(), 'query');
        $db->disableQueryLog();

        return [json_decode((string) $response->getBody(), true), $queries];
    }

    #[Test]
    public function off_by_default_nothing_is_added(): void
    {
        $this->show(1);
        [$body, $queries] = $this->show(1);

        $this->assertArrayNotHasKey('surveyorRelated', $body['data']['attributes']);
        $this->assertSame([], array_values(array_filter($queries, fn ($q) => str_contains($q, 'discussion_tag') && str_contains($q, 'surveyor_dt'))));
    }

    #[Test]
    public function on_it_lists_related_discussions_for_one_cached_query(): void
    {
        $this->extend(...(new RelatedBlock())());

        // Measured against the same request with the block off, below.
        $this->show(20); // warm up (assets, settings)
        $this->app()->getContainer()->make(\Illuminate\Contracts\Cache\Repository::class)->forget('ernestdefoe-surveyor.related.1.5');

        [$first, $missQueries] = $this->show(1);
        [, $hitQueries] = $this->show(1);

        $this->assertSame([['id' => 20, 'title' => 'Climbing roses', 'slug' => '20-climbing-roses']], $first['data']['attributes']['surveyorRelated']);
        $this->assertCount(count($hitQueries) + 1, $missQueries, "a cache miss costs one query:\n".implode("\n", array_diff($missQueries, $hitQueries)));

        if ($log = getenv('SURVEYOR_LEAN_LOG')) {
            file_put_contents($log.'.related', json_encode(['miss' => count($missQueries), 'hit' => count($hitQueries)]));
        }
    }

    #[Test]
    public function cached_it_costs_the_same_as_off(): void
    {
        $this->show(1);
        [, $off] = $this->show(1);

        // The same forum booted again with the block's extenders applied.
        $this->database()->rollBack();
        $this->app = null;
        $this->server = null;
        $this->database = null;
        $this->extend(...(new RelatedBlock())());
        $this->app();

        $this->show(1);
        [, $on] = $this->show(1);

        $this->assertCount(count($off), $on);

        if ($log = getenv('SURVEYOR_LEAN_LOG')) {
            file_put_contents($log.'.related_off_on', json_encode(['off' => count($off), 'on_cached' => count($on)]));
        }
    }
}
