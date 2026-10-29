<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Google\Opportunities;
use PHPUnit\Framework\Attributes\Test;

class OpportunitiesTest extends SurveyorTestCase
{
    private static function row(int $clicks, int $impressions, ?float $position, int $prevClicks = 0): object
    {
        return (object) ['clicks' => $clicks, 'impressions' => $impressions, 'position' => $position === null ? null : (int) round($position * 100), 'prev_clicks' => $prevClicks, 'prev_impressions' => 0, 'prev_position' => null];
    }

    #[Test]
    public function pages_are_scored_by_the_clicks_they_could_win(): void
    {
        // Position 3 usually gets ~10% of clicks; 1% on 2 000 impressions is far below.
        $low = Opportunities::find(self::row(20, 2000, 3.0));
        $this->assertSame('low_ctr', $low[0]['type']);
        $this->assertSame(180, $low[0]['potential']);
        $this->assertSame('high', $low[0]['priority']);

        // A healthy page at position 3: nothing to do.
        $this->assertSame([], Opportunities::find(self::row(220, 2000, 3.0)));

        // Position 12 with 300 impressions: on page one's doorstep.
        $striking = Opportunities::find(self::row(1, 300, 12.0));
        $this->assertSame(['low_ctr', 'striking'], array_column($striking, 'type'));
        $this->assertSame(14, $striking[1]['potential']);
        $this->assertSame('medium', $striking[1]['priority']);

        // Too few impressions to judge.
        $this->assertSame([], Opportunities::find(self::row(0, 40, 12.0)));

        // From 40 clicks to 10: losing them.
        $declining = Opportunities::find(self::row(10, 30, 5.0, 40));
        $this->assertSame([['type' => 'declining', 'potential' => 30, 'priority' => 'medium']], $declining);
        $this->assertSame([], Opportunities::find(self::row(30, 30, 5.0, 40)), 'a 25% dip is not a decline');

        $this->assertSame('low', Opportunities::priority(9.9));
        $this->assertSame(0.28, Opportunities::expectedCtr(0.6));
        $this->assertLessThan(Opportunities::expectedCtr(10), Opportunities::expectedCtr(15));
    }

    #[Test]
    public function the_list_comes_with_queries_and_the_audits_view_most_potential_first(): void
    {
        $now = Carbon::now();
        $this->prepareDatabase([
            'surveyor_search_pages' => [
                ['id' => 1, 'url_hash' => sha1('/d/1-welcome'), 'path' => '/d/1-welcome', 'clicks' => 20, 'impressions' => 2000, 'position' => 300, 'prev_clicks' => 25, 'prev_impressions' => 2000, 'prev_position' => 300, 'period_end' => '2026-10-26', 'updated_at' => $now],
                ['id' => 2, 'url_hash' => sha1('/t/roses'), 'path' => '/t/roses', 'clicks' => 1, 'impressions' => 300, 'position' => 1200, 'prev_clicks' => 0, 'prev_impressions' => 0, 'prev_position' => null, 'period_end' => '2026-10-26', 'updated_at' => $now],
            ],
            'surveyor_search_queries' => [
                ['url_hash' => sha1('/d/1-welcome'), 'query_hash' => sha1('garden club'), 'query' => 'garden club', 'clicks' => 10, 'impressions' => 900, 'position' => 280],
            ],
            'surveyor_pages' => [
                ['id' => 1, 'url_hash' => sha1('/d/1-welcome'), 'path' => '/d/1-welcome', 'found_via' => 'sitemap', 'score' => 80, 'issues' => '["description_short"]', 'classification' => 'ok', 'crawled_at' => $now],
            ],
        ]);

        $list = $this->json($this->api('GET', '/opportunities'));

        $this->assertSame('/d/1-welcome', $list['data'][0]['page']['path']);
        $this->assertSame('low_ctr', $list['data'][0]['type']);
        $this->assertSame('garden club', $list['data'][0]['queries'][0]['query']);
        $this->assertSame(['description_short'], $list['data'][0]['auditIssues']);
        $this->assertSame(1, $list['counts']['high']);

        $this->assertSame(['striking'], array_column($this->json($this->api('GET', '/opportunities', query: ['type' => 'striking']))['data'], 'type'));
    }
}
