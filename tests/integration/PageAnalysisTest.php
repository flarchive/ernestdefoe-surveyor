<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Ernestdefoe\Surveyor\Crawl\Analyzer;
use Ernestdefoe\Surveyor\Crawl\Scorer;
use Ernestdefoe\Surveyor\Http\Page;
use PHPUnit\Framework\Attributes\Test;

class PageAnalysisTest extends SurveyorTestCase
{
    private function analyze(string $html, string $url = 'http://localhost/d/7-pruning-roses', array $headers = []): array
    {
        /** @var Analyzer $analyzer */
        $analyzer = $this->make(Analyzer::class);

        return $analyzer->analyze(new Page($url, 200, $headers + ['content-type' => 'text/html'], $html, $url));
    }

    #[Test]
    public function reads_the_signals_from_a_flarum_discussion_page(): void
    {
        $a = $this->analyze((string) file_get_contents(__DIR__.'/fixtures/discussion.html'));

        $this->assertSame('Pruning roses in late winter - The Garden Club', $a['title']);
        $this->assertSame(46, $a['title_length']);
        $this->assertSame(116, $a['description_length']);
        $this->assertSame('http://localhost/d/7-pruning-roses', $a['canonical']);
        $this->assertTrue($a['canonical_self']);
        $this->assertFalse($a['noindex']);
        $this->assertSame(1, $a['h1_count']);
        $this->assertSame('valid', $a['structured_data']);
        // Only the image with no alt attribute at all; alt="" marks a decorative image.
        $this->assertSame(1, $a['images_no_alt']);
        // The content Flarum renders for guests, not the script.
        $this->assertGreaterThan(30, $a['word_count']);
        $this->assertLessThan(60, $a['word_count']);

        // Own links only, absolute, no fragments; the mail, the shop and the anchor are not links to check.
        $this->assertEqualsCanonicalizing([
            'http://localhost',
            'http://localhost/d/8-rose-diseases',
            'http://localhost/t/roses',
            'http://localhost/d/404-gone',
            'http://localhost/settings',
        ], $a['links']);

        [$score, $issues] = Scorer::score($a, 120);
        $this->assertSame(['thin_content', 'images_no_alt'], $issues);
        $this->assertSame(90, $score);
    }

    #[Test]
    public function catches_missing_and_broken_signals(): void
    {
        $html = '<html><head><meta name="robots" content="noindex, follow"><link rel="canonical" href="/d/1">'
            .'<script type="application/ld+json">{"@type": "Thing",</script></head>'
            .'<body><h1>One</h1><h1>Two</h1><p>Short.</p></body></html>';

        $a = $this->analyze($html, 'http://localhost/d/1-hello');

        $this->assertNull($a['title']);
        $this->assertNull($a['description_length']);
        $this->assertSame('http://localhost/d/1', $a['canonical']);
        $this->assertFalse($a['canonical_self']);
        $this->assertTrue($a['noindex']);
        $this->assertSame(2, $a['h1_count']);
        $this->assertSame('invalid', $a['structured_data']);

        [$score, $issues] = Scorer::score($a, 2000);
        $this->assertSame(['title_missing', 'description_missing', 'canonical_elsewhere', 'noindex', 'h1_multiple', 'thin_content', 'structured_data_invalid', 'slow'], $issues);
        $this->assertSame(100 - 15 - 15 - 5 - 10 - 10 - 5 - 5 - 5, $score);
    }

    #[Test]
    public function the_x_robots_tag_header_counts_as_noindex(): void
    {
        $a = $this->analyze('<html><head><title>Fine title here</title></head><body></body></html>', 'http://localhost/', ['x-robots-tag' => 'none']);

        $this->assertTrue($a['noindex']);
        $this->assertNull($a['canonical']);
        $this->assertSame('none', $a['structured_data']);
    }

    #[Test]
    public function titles_are_read_as_text_even_when_they_contain_markup(): void
    {
        $a = $this->analyze('<html><head><title>&lt;script&gt;alert(1)&lt;/script&gt; Roses &amp; more</title></head><body></body></html>');

        // Text, decoded once: the admin shows it as text, never as HTML.
        $this->assertSame('<script>alert(1)</script> Roses & more', $a['title']);
    }

    #[Test]
    public function the_weights_add_up_to_one_hundred_and_a_perfect_page_scores_it(): void
    {
        $byGroup = Scorer::WEIGHTS;
        $max = $byGroup['title_missing'] + $byGroup['title_short'] + $byGroup['description_missing'] + $byGroup['description_short']
            + $byGroup['canonical_missing'] + $byGroup['canonical_elsewhere'] + $byGroup['noindex'] + $byGroup['h1_missing']
            + $byGroup['thin_content'] + $byGroup['images_no_alt'] + $byGroup['structured_data_missing'] + $byGroup['slow'];
        $this->assertSame(100, $max);

        $html = self::html('A perfectly reasonable title', self::words(80), 'http://localhost/d/1-hello');
        [$score, $issues] = Scorer::score($this->analyze($html, 'http://localhost/d/1-hello'), 100);
        $this->assertSame([], $issues);
        $this->assertSame(100, $score);
    }
}
