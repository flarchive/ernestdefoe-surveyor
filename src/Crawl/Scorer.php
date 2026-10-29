<?php

namespace Ernestdefoe\Surveyor\Crawl;

/**
 * A page's score out of 100: every check that fails takes its weight off.
 * The weights add up to 100 and are listed in the README, so an admin can
 * see exactly why a page scored what it did.
 */
final class Scorer
{
    /** @var array<string, int> issue code => points it costs */
    public const WEIGHTS = [
        'title_missing' => 15,
        'title_short' => 10,
        'title_long' => 10,
        'description_missing' => 15,
        'description_short' => 5,
        'description_long' => 5,
        'canonical_missing' => 10,
        'canonical_elsewhere' => 5,
        'noindex' => 10,
        'h1_missing' => 10,
        'h1_multiple' => 10,
        'thin_content' => 5,
        'images_no_alt' => 5,
        'structured_data_missing' => 5,
        'structured_data_invalid' => 5,
        'slow' => 5,
    ];

    public const TITLE_MIN = 10;
    public const TITLE_MAX = 65;
    public const DESCRIPTION_MIN = 50;
    public const DESCRIPTION_MAX = 160;
    public const THIN_WORDS = 50;
    public const SLOW_MS = 1500;

    /**
     * @param array<string, mixed> $a from Analyzer::analyze()
     * @return array{0: int, 1: list<string>} score, issue codes
     */
    public static function score(array $a, int $ms): array
    {
        $issues = [];

        if ($a['title_length'] === null) {
            $issues[] = 'title_missing';
        } elseif ($a['title_length'] < self::TITLE_MIN) {
            $issues[] = 'title_short';
        } elseif ($a['title_length'] > self::TITLE_MAX) {
            $issues[] = 'title_long';
        }

        if ($a['description_length'] === null || $a['description_length'] === 0) {
            $issues[] = 'description_missing';
        } elseif ($a['description_length'] < self::DESCRIPTION_MIN) {
            $issues[] = 'description_short';
        } elseif ($a['description_length'] > self::DESCRIPTION_MAX) {
            $issues[] = 'description_long';
        }

        if ($a['canonical'] === null) {
            $issues[] = 'canonical_missing';
        } elseif ($a['canonical_self'] === false) {
            $issues[] = 'canonical_elsewhere';
        }

        if ($a['noindex']) {
            $issues[] = 'noindex';
        }

        if ($a['h1_count'] === 0) {
            $issues[] = 'h1_missing';
        } elseif ($a['h1_count'] > 1) {
            $issues[] = 'h1_multiple';
        }

        if ($a['word_count'] < self::THIN_WORDS) {
            $issues[] = 'thin_content';
        }
        if ($a['images_no_alt'] > 0) {
            $issues[] = 'images_no_alt';
        }

        if ($a['structured_data'] === 'none') {
            $issues[] = 'structured_data_missing';
        } elseif ($a['structured_data'] === 'invalid') {
            $issues[] = 'structured_data_invalid';
        }

        if ($ms > self::SLOW_MS) {
            $issues[] = 'slow';
        }

        $score = 100;
        foreach ($issues as $issue) {
            $score -= self::WEIGHTS[$issue];
        }

        return [max(0, $score), $issues];
    }
}
