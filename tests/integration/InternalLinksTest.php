<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Links\Related;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use PHPUnit\Framework\Attributes\Test;

class InternalLinksTest extends SurveyorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $old = Carbon::now()->subDays(5)->toDateTimeString();
        $new = Carbon::now()->subDay()->toDateTimeString();
        $d = fn ($id, $title, $at, $extra = []) => $extra + ['id' => $id, 'title' => $title, 'slug' => strtolower(str_replace(' ', '-', $title)), 'created_at' => $at, 'last_posted_at' => $at, 'user_id' => 2, 'first_post_id' => $id, 'comment_count' => 1, 'last_post_number' => 1];

        $this->prepareDatabase([
            Discussion::class => [
                $d(10, 'Pruning climbing roses in winter', $old),
                $d(11, 'Winter pruning for climbing roses #roses', $old),
                $d(12, 'Climbing roses that flower twice', $new),
                $d(13, 'Tomatoes in pots', $new),
                $d(14, 'Secret climbing roses plan', $new, ['is_private' => true]),
            ],
            Post::class => array_map(fn ($id) => ['id' => $id, 'discussion_id' => $id, 'created_at' => $old, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Hi</p></t>', 'number' => 1], [10, 11, 12, 13, 14]),
            'tags' => [
                ['id' => 1, 'name' => 'Roses', 'slug' => 'roses', 'position' => 0, 'is_restricted' => false],
                ['id' => 2, 'name' => 'Climbers', 'slug' => 'climbers', 'position' => 1, 'is_restricted' => false],
                ['id' => 3, 'name' => 'Vegetables', 'slug' => 'vegetables', 'position' => 2, 'is_restricted' => false],
            ],
            'discussion_tag' => [
                ['discussion_id' => 10, 'tag_id' => 1], ['discussion_id' => 10, 'tag_id' => 2],
                ['discussion_id' => 11, 'tag_id' => 1], ['discussion_id' => 11, 'tag_id' => 2],
                ['discussion_id' => 12, 'tag_id' => 1],
                ['discussion_id' => 13, 'tag_id' => 3],
                ['discussion_id' => 14, 'tag_id' => 1], ['discussion_id' => 14, 'tag_id' => 2],
            ],
        ]);
    }

    #[Test]
    public function related_discussions_share_tags_and_words_and_are_public(): void
    {
        $this->app();
        $related = $this->make(Related::class)->find(Discussion::query()->find(10), 5);

        $this->assertSame([11, 12], array_column($related, 'id'));
        $this->assertSame(2, $related[0]['sharedTags']);
        $this->assertEqualsCanonicalizing(['pruning', 'climbing', 'roses', 'winter'], $related[0]['sharedWords']);
        $this->assertSame('11-winter-pruning-for-climbing-roses-#roses', $related[0]['slug']);

        $this->assertSame(['pruning', '#roses', 'grow'], Related::words('Pruning #roses: how to grow'));

        $api = $this->json($this->api('GET', '/related', query: ['discussion' => 'http://localhost/d/10-pruning']));
        $this->assertSame(10, $api['discussion']['id']);
        $this->assertSame([11, 12], array_column($api['data'], 'id'));
        $this->assertNull($this->json($this->api('GET', '/related', query: ['discussion' => '14']))['discussion'], 'private');
    }

    #[Test]
    public function orphans_weak_pages_and_hubs_come_from_the_audits_links(): void
    {
        $now = Carbon::now();
        $page = fn ($id, $path, $via = 'sitemap', $out = 0) => ['id' => $id, 'url_hash' => sha1($path), 'path' => $path, 'found_via' => $via, 'classification' => 'ok', 'score' => 90, 'links_out' => $out, 'crawled_at' => $now];

        $this->prepareDatabase([
            'surveyor_pages' => [$page(1, '/', 'home', 3), $page(2, '/d/a', 'sitemap', 1), $page(3, '/d/b'), $page(4, '/d/orphan')],
            'surveyor_links' => [
                ['source_id' => 1, 'target_id' => 2], ['source_id' => 1, 'target_id' => 3],
                ['source_id' => 2, 'target_id' => 3],
            ],
        ]);

        $graph = $this->json($this->api('GET', '/internal-links'));

        $this->assertSame(['/d/orphan'], array_column($graph['orphans'], 'path'));
        $this->assertSame(['/d/a'], array_column($graph['weak'], 'path'));
        $this->assertSame('/d/b', $graph['mostLinked'][0]['path']);
        $this->assertSame(2, $graph['mostLinked'][0]['inbound']);
        $this->assertSame('/', $graph['mostLinking'][0]['path']);
    }
}
