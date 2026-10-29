<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;

class RegressionsTest extends SurveyorTestCase
{
    #[Test]
    public function drops_against_the_last_healthy_measurement_are_listed(): void
    {
        $now = Carbon::now();
        $speed = fn ($id, $path, $score, $lcp) => ['id' => $id, 'url_hash' => sha1($path), 'path' => $path, 'strategy' => 'mobile', 'score' => $score, 'lcp_ms' => $lcp, 'measured_at' => $now];

        $this->prepareDatabase([
            'surveyor_runs' => [
                ['id' => 1, 'status' => 'done', 'phase' => 'done', 'origin' => 'manual', 'started_at' => $now->copy()->subWeeks(2)],
                ['id' => 2, 'status' => 'done', 'phase' => 'done', 'origin' => 'manual', 'started_at' => $now->copy()->subWeek()],
            ],
            'surveyor_pages' => [
                ['id' => 1, 'url_hash' => sha1('/a'), 'path' => '/a', 'found_via' => 'sitemap'],
                ['id' => 2, 'url_hash' => sha1('/b'), 'path' => '/b', 'found_via' => 'sitemap'],
                ['id' => 3, 'url_hash' => sha1('/c'), 'path' => '/c', 'found_via' => 'sitemap'],
            ],
            'surveyor_scores' => [
                ['run_id' => 1, 'page_id' => 1, 'score' => 95, 'status_code' => 200],
                ['run_id' => 2, 'page_id' => 1, 'score' => 70, 'status_code' => 200],
                ['run_id' => 1, 'page_id' => 2, 'score' => 90, 'status_code' => 200],
                ['run_id' => 2, 'page_id' => 2, 'score' => null, 'status_code' => 404],
                ['run_id' => 1, 'page_id' => 3, 'score' => 90, 'status_code' => 200],
                ['run_id' => 2, 'page_id' => 3, 'score' => 85, 'status_code' => 200],
            ],
            'surveyor_search_pages' => [
                ['id' => 1, 'url_hash' => sha1('/a'), 'path' => '/a', 'clicks' => 10, 'impressions' => 500, 'position' => 500, 'prev_clicks' => 40, 'prev_impressions' => 600, 'period_end' => '2026-10-26', 'updated_at' => $now],
                ['id' => 2, 'url_hash' => sha1('/c'), 'path' => '/c', 'clicks' => 35, 'impressions' => 500, 'position' => 500, 'prev_clicks' => 40, 'prev_impressions' => 600, 'period_end' => '2026-10-26', 'updated_at' => $now],
            ],
            'surveyor_speed' => [
                $speed(1, '/a', 90, 2000), $speed(2, '/a', 88, 2100), $speed(3, '/a', 70, 2200),
                $speed(4, '/c', 80, 2000), $speed(5, '/c', 79, 3000),
            ],
        ]);

        $found = $this->json($this->api('GET', '/regressions'))['data'];
        $kinds = array_map(fn ($r) => $r['kind'].($r['metric'] ?? '').':'.$r['path'], $found);

        $this->assertEqualsCanonicalizing(['score:/a', 'broken:/b', 'visibilityclicks:/a', 'performancescore:/a', 'performancelcp:/c'], $kinds);

        $score = array_values(array_filter($found, fn ($r) => $r['kind'] === 'score'))[0];
        $this->assertSame(95, $score['before']);
        $this->assertSame(70, $score['now']);
    }
}
