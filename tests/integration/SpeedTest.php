<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Ernestdefoe\Surveyor\Speed\PageSpeed;
use PHPUnit\Framework\Attributes\Test;

class SpeedTest extends SurveyorTestCase
{
    public static function psi(float $score = 0.87, float $lcp = 2400.4): array
    {
        return ['status' => 200, 'body' => [
            'lighthouseResult' => [
                'categories' => ['performance' => ['score' => $score]],
                'audits' => [
                    'largest-contentful-paint' => ['numericValue' => $lcp],
                    'first-contentful-paint' => ['numericValue' => 1200],
                    'total-blocking-time' => ['numericValue' => 150.6],
                    'speed-index' => ['numericValue' => 2100],
                    'cumulative-layout-shift' => ['numericValue' => 0.042],
                ],
            ],
            'loadingExperience' => [],
            'originLoadingExperience' => [
                'overall_category' => 'AVERAGE',
                'metrics' => [
                    'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 2900],
                    'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 180],
                    'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 5],
                ],
            ],
        ]];
    }

    #[Test]
    public function a_queued_page_is_measured_on_both_strategies_and_kept_as_history(): void
    {
        $this->fakes();
        $this->http->on('pagespeedonline', self::psi());

        $this->json($this->api('POST', '/speed', ['path' => '/d/1-welcome']));
        $this->assertSame(2, $this->database()->table('surveyor_speed_queue')->count());

        /** @var PageSpeed $speed */
        $speed = $this->make(PageSpeed::class);
        $this->assertTrue($speed->next());
        $this->assertTrue($speed->next());
        $this->assertFalse($speed->next(), 'queue empty');

        $calls = $this->http->to('pagespeedonline');
        $this->assertCount(2, $calls);
        parse_str((string) parse_url($calls[0]['url'], PHP_URL_QUERY), $query);
        $this->assertSame('http://localhost/d/1-welcome', $query['url']);
        $this->assertSame('performance', $query['category']);
        $this->assertArrayNotHasKey('key', $query);

        $row = $this->database()->table('surveyor_speed')->where('strategy', 'mobile')->first();
        $this->assertSame(87, (int) $row->score);
        $this->assertSame(2400, (int) $row->lcp_ms);
        $this->assertSame(151, (int) $row->tbt_ms);
        $this->assertSame(42, (int) $row->cls);
        $this->assertSame(2900, (int) $row->field_lcp_ms);
        $this->assertSame(180, (int) $row->field_inp_ms);
        $this->assertSame(50, (int) $row->field_cls);
        $this->assertSame('AVERAGE', $row->field_category);
        $this->assertSame('origin', $row->field_scope);

        $shown = $this->json($this->api('GET', '/speed'));
        $this->assertCount(2, $shown['data']);
        $this->assertSame(0.05, $shown['data'][0]['fieldCls']);
    }

    #[Test]
    public function the_key_is_used_caps_hold_and_failures_pause(): void
    {
        $this->fakes();
        $this->api('POST', '/speed/key', ['key' => 'AIzaSyA-abcdefghijklmnopqrstuvwxyz012']);
        $this->http->on('pagespeedonline', ['status' => 429, 'body' => ['error' => ['code' => 429, 'message' => 'Quota exceeded']]]);

        /** @var PageSpeed $speed */
        $speed = $this->make(PageSpeed::class);
        $speed->queue('/', ['mobile']);
        $speed->queue('/t/roses', ['mobile']);
        $speed->next();

        parse_str((string) parse_url($this->http->calls[0]['url'], PHP_URL_QUERY), $query);
        $this->assertSame('AIzaSyA-abcdefghijklmnopqrstuvwxyz012', $query['key']);
        $this->assertSame('Quota exceeded', $this->database()->table('surveyor_speed')->value('error'));

        // Paused: the next queued page waits.
        $this->assertFalse($this->make(PageSpeed::class)->next());
        $this->assertCount(1, $this->http->calls);
        $this->assertSame(1, $this->database()->table('surveyor_speed_queue')->count());
    }

    #[Test]
    public function only_the_newest_twenty_measurements_are_kept(): void
    {
        $this->fakes();
        $this->http->on('pagespeedonline', self::psi());
        $speed = $this->make(PageSpeed::class);

        for ($i = 0; $i < 22; $i++) {
            $speed->measure('/', 'desktop');
        }

        $this->assertSame(PageSpeed::KEEP, $this->database()->table('surveyor_speed')->count());
    }
}
