<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Ernestdefoe\Surveyor\Crawl\Auditor;
use Ernestdefoe\Surveyor\Settings;
use PHPUnit\Framework\Attributes\Test;

class SiteAuditTest extends SurveyorTestCase
{
    private function site(): void
    {
        $this->fakes();
        $base = self::BASE;

        $this->fetcher->text("$base/robots.txt", "User-agent: *\nDisallow: /u/private\nSitemap: $base/sitemap.xml\n");
        $this->fetcher->text("$base/sitemap.xml", '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            ."<sitemap><loc>$base/sitemap-1.xml</loc></sitemap><sitemap><loc>https://evil.example/sitemap.xml</loc></sitemap></sitemapindex>", 200, 'application/xml');
        $this->fetcher->text("$base/sitemap-1.xml", '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            ."<url><loc>$base/d/1-welcome</loc></url><url><loc>$base/t/roses</loc></url><url><loc>https://elsewhere.example/d/9</loc></url>"
            ."<url><loc>$base/settings</loc></url></urlset>", 200, 'application/xml');

        $this->fetcher->html("$base/", self::html('The Garden Club forum home', self::words(80).'<a href="/d/1-welcome">Welcome</a> <a href="/u/private">x</a>', "$base"));
        $this->fetcher->html("$base/d/1-welcome", self::html('Welcome to the garden club', self::words(80)
            .'<a href="/d/2-secret-garden-plans">secret</a> <a href="/d/99-gone">gone</a> <a href="https://example.com/x">out</a>'
            .' <a href="/notifications">n</a> <a href="/old-page">old</a>', "$base/d/1-welcome"));
        $this->fetcher->html("$base/t/roses", self::html('Roses', '<p>Few words</p>', "$base/t/roses", 'Short'));
        $this->fetcher->pages["$base/old-page"] = new \Ernestdefoe\Surveyor\Http\Page("$base/old-page", 200, ['content-type' => 'text/html'],
            self::html('Moved here page title', self::words(80), "$base/new-page"), "$base/new-page", [['status' => 301, 'location' => "$base/new-page"]], 40, 100);
    }

    private function runAudit(int $units = 100): Auditor
    {
        /** @var Auditor $auditor */
        $auditor = $this->make(Auditor::class);
        $auditor->start('manual');
        $auditor->work($units, 0, microtime(true) + 60);

        return $auditor;
    }

    private function page(string $path): ?object
    {
        return $this->database()->table('surveyor_pages')->where('path', $path)->first();
    }

    #[Test]
    public function a_run_reads_robots_and_the_sitemap_then_audits_pages_as_a_guest(): void
    {
        $this->site();
        $auditor = $this->runAudit();

        $run = $auditor->latest();
        $this->assertSame('done', $run->status);
        $this->assertNotNull($run->avg_score);

        // Never requested: other hosts, private routes, robots-disallowed pages.
        foreach ($this->fetcher->requested as $url) {
            $this->assertStringStartsWith('http://localhost/', $url);
            $this->assertStringNotContainsString('/settings', $url);
            $this->assertStringNotContainsString('/notifications', $url);
            $this->assertStringNotContainsString('/u/private', $url);
        }
        $this->assertNull($this->page('/settings'));
        $this->assertNull($this->page('/u/private'));

        $home = $this->page('/');
        $this->assertSame('ok', $home->classification);
        $this->assertSame(100, (int) $home->score);

        $roses = $this->page('/t/roses');
        $this->assertSame('sitemap', $roses->found_via);
        $this->assertContains('thin_content', json_decode($roses->issues, true));
        $this->assertContains('description_short', json_decode($roses->issues, true));

        // Links found on pages: classified as a guest sees them.
        $this->assertSame('restricted', $this->page('/d/2-secret-garden-plans')->classification);
        $this->assertSame('broken', $this->page('/d/99-gone')->classification);
        $old = $this->page('/old-page');
        $this->assertSame('redirect', $old->classification);
        $this->assertSame('http://localhost/new-page', $old->final_url);

        // Every link to a broken page knows its source page.
        $welcome = $this->page('/d/1-welcome');
        $this->assertTrue($this->database()->table('surveyor_links')->where('source_id', $welcome->id)->where('target_id', $this->page('/d/99-gone')->id)->exists());
        $this->assertSame(4, $this->database()->table('surveyor_links')->where('source_id', $welcome->id)->count());

        // History: one score row per page in this run.
        $this->assertSame($run->pages_done, $this->database()->table('surveyor_scores')->where('run_id', $run->id)->count());
    }

    #[Test]
    public function the_api_lists_pages_links_and_duplicates(): void
    {
        $this->site();
        $this->runAudit();

        $pages = $this->json($this->api('GET', '/pages', query: ['issue' => 'thin_content']));
        $this->assertSame(['/t/roses'], array_column($pages['data'], 'path'));

        $links = $this->json($this->api('GET', '/links'));
        $byPath = array_column($links['data'], null, 'path');
        $this->assertSame('broken', $byPath['/d/99-gone']['classification']);
        $this->assertSame(1, $byPath['/d/99-gone']['sources']);
        $this->assertSame('restricted', $byPath['/d/2-secret-garden-plans']['classification']);
        $this->assertArrayNotHasKey('/', $byPath);

        $sources = $this->json($this->api('GET', '/links/'.$byPath['/d/99-gone']['id'].'/sources'));
        $this->assertSame(['/d/1-welcome'], array_column($sources['data'], 'path'));

        // Ignored links leave the list, and come back when restored.
        $this->api('PATCH', '/links/'.$byPath['/d/99-gone']['id'], ['ignored' => true]);
        $this->assertNotContains('/d/99-gone', array_column($this->json($this->api('GET', '/links'))['data'], 'path'));
        $this->assertContains('/d/99-gone', array_column($this->json($this->api('GET', '/links', query: ['ignored' => 1]))['data'], 'path'));

        // Recheck: the page now exists.
        $this->fetcher->html('http://localhost/d/99-gone', self::html('It came back after all', self::words(60), 'http://localhost/d/99-gone'));
        $rechecked = $this->json($this->api('POST', '/links/'.$byPath['/d/99-gone']['id'].'/recheck'));
        $this->assertSame('ok', $rechecked['data']['classification']);

        $summary = $this->json($this->api('GET', '/summary'));
        $this->assertGreaterThan(0, $summary['pages']);
        $this->assertSame(1, $summary['issues']['thin_content']);

        $detail = $this->json($this->api('GET', '/pages/'.$this->page('/')->id));
        $this->assertSame(100, $detail['data']['score']);
        $this->assertCount(1, $detail['data']['history']);

        $this->assertSame([], $this->json($this->api('GET', '/duplicates'))['data']);
    }

    #[Test]
    public function duplicate_titles_are_grouped(): void
    {
        $this->fakes();
        $base = self::BASE;
        $this->fetcher->html("$base/", self::html('Home page of the forum', self::words(80).'<a href="/d/1">a</a><a href="/d/3">b</a>', $base));
        $this->fetcher->html("$base/d/1", self::html('Same old title here', self::words(80), "$base/d/1"));
        $this->fetcher->html("$base/d/3", self::html('Same old title here', self::words(80), "$base/d/3"));
        $this->runAudit();

        $groups = $this->json($this->api('GET', '/duplicates'))['data'];
        $this->assertCount(1, $groups);
        $this->assertSame('Same old title here', $groups[0]['text']);
        $this->assertSame(2, $groups[0]['total']);

        $descriptions = $this->json($this->api('GET', '/duplicates', query: ['kind' => 'description']))['data'];
        $this->assertSame(3, $descriptions[0]['total']);
    }

    #[Test]
    public function a_run_stops_at_the_page_cap_resumes_where_it_left_off_and_keeps_one_lock(): void
    {
        $this->site();
        $this->setting(Settings::key('max_pages'), '10');
        $this->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set(Settings::key('max_pages'), '10');

        /** @var Auditor $auditor */
        $auditor = $this->make(Auditor::class);
        $auditor->start('schedule');

        // robots + two sitemaps + the first page.
        $this->assertSame(4, $auditor->work(4, 0, microtime(true) + 60));
        $this->assertSame('crawl', $auditor->current()->phase);

        // Another process holding the run gets nothing.
        $this->database()->table('surveyor_runs')->update(['locked_until' => \Carbon\Carbon::now()->addMinute()]);
        $this->assertNull($auditor->work(5, 0, microtime(true) + 60));
        $this->database()->table('surveyor_runs')->update(['locked_until' => null]);

        // A fresh Auditor (a later tick) carries on with the same run.
        $again = $this->make(Auditor::class);
        $again->work(100, 0, microtime(true) + 60);
        $run = $again->latest();

        $this->assertSame('done', $run->status);
        $this->assertLessThanOrEqual(10, (int) $run->pages_queued);
        $this->assertSame((int) $run->pages_queued, (int) $run->pages_done);
        $this->assertCount(count(array_unique($this->fetcher->requested)), $this->fetcher->requested, 'no page fetched twice');
    }

    #[Test]
    public function stopping_a_run_clears_its_queue_and_old_history_is_pruned(): void
    {
        $this->site();
        $this->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set(Settings::key('keep_runs'), '2');

        /** @var Auditor $auditor */
        $auditor = $this->make(Auditor::class);
        $auditor->start('manual');
        $auditor->work(5, 0, microtime(true) + 60);
        $auditor->stop();

        $this->assertNull($auditor->current());
        $this->assertSame(0, $this->database()->table('surveyor_pages')->whereNotNull('queued_run')->count());
        $this->assertSame('stopped', $auditor->latest()->status);

        for ($i = 0; $i < 3; $i++) {
            $auditor->start('manual');
            $auditor->work(100, 0, microtime(true) + 60);
        }

        $this->assertSame(2, $this->database()->table('surveyor_runs')->count());
        $this->assertSame(0, $this->database()->table('surveyor_scores')->whereNotIn('run_id', $this->database()->table('surveyor_runs')->pluck('id'))->count());
    }

    #[Test]
    public function audit_endpoints_start_step_and_stop(): void
    {
        $this->site();

        $started = $this->json($this->api('POST', '/audit/start'));
        $this->assertSame('running', $started['run']['status']);

        $stepped = $this->json($this->api('POST', '/audit/step'));
        $this->assertFalse($stepped['busy']);
        $this->assertNotSame('robots', $stepped['run']['phase']);

        $stopped = $this->json($this->api('POST', '/audit/stop'));
        $this->assertSame('stopped', $stopped['run']['status']);
    }
}
