<?php

namespace Ernestdefoe\Surveyor\Google;

/**
 * Where a page could win more clicks, from its last 28 days in search.
 *
 *  - low_ctr:   seen often (100+ impressions) but clicked less than half as
 *               often as pages at that position usually are
 *  - striking:  on page one's doorstep, average position 8–20, 50+ impressions
 *  - declining: clicks down 30% or more on the 28 days before (from 10+)
 *
 * Potential = the extra clicks a month if the page reached the expected
 * click-through rate (low_ctr), position 5 (striking) or its old clicks
 * (declining). High from 50, Medium from 10, else Low.
 */
final class Opportunities
{
    public const MIN_IMPRESSIONS_CTR = 100;
    public const MIN_IMPRESSIONS_STRIKING = 50;
    public const MIN_PREV_CLICKS = 10;
    public const DECLINE = 0.3;

    /** Typical organic click-through rate by position (1–10), then a long tail. */
    public static function expectedCtr(float $position): float
    {
        $curve = [1 => 0.28, 2 => 0.15, 3 => 0.10, 4 => 0.07, 5 => 0.05, 6 => 0.04, 7 => 0.03, 8 => 0.025, 9 => 0.02, 10 => 0.018];
        $p = max(1, (int) round($position));

        return $curve[$p] ?? max(0.002, 0.018 * 10 / $p);
    }

    public static function priority(float $potential): string
    {
        return $potential >= 50 ? 'high' : ($potential >= 10 ? 'medium' : 'low');
    }

    /**
     * @param object $row a surveyor_search_pages row (positions × 100)
     * @return list<array{type: string, potential: int, priority: string}>
     */
    public static function find(object $row): array
    {
        $found = [];
        $impressions = (int) $row->impressions;
        $clicks = (int) $row->clicks;
        $position = $row->position === null ? null : $row->position / 100;
        $ctr = $impressions > 0 ? $clicks / $impressions : 0.0;

        if ($position !== null && $impressions >= self::MIN_IMPRESSIONS_CTR) {
            $expected = self::expectedCtr($position);
            if ($ctr < $expected / 2) {
                $potential = $impressions * ($expected - $ctr);
                $found[] = ['type' => 'low_ctr', 'potential' => (int) round($potential), 'priority' => self::priority($potential)];
            }
        }

        if ($position !== null && $position >= 8 && $position <= 20 && $impressions >= self::MIN_IMPRESSIONS_STRIKING) {
            $potential = max(0, $impressions * self::expectedCtr(5) - $clicks);
            $found[] = ['type' => 'striking', 'potential' => (int) round($potential), 'priority' => self::priority($potential)];
        }

        $prev = (int) $row->prev_clicks;
        if ($prev >= self::MIN_PREV_CLICKS && $clicks <= $prev * (1 - self::DECLINE)) {
            $potential = $prev - $clicks;
            $found[] = ['type' => 'declining', 'potential' => $potential, 'priority' => self::priority($potential)];
        }

        return $found;
    }
}
