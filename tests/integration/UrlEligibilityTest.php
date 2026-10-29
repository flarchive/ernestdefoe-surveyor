<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Ernestdefoe\Surveyor\Crawl\Eligibility;
use Ernestdefoe\Surveyor\Crawl\Robots;
use Ernestdefoe\Surveyor\Http\GuzzleFetcher;
use Ernestdefoe\Surveyor\Site;
use PHPUnit\Framework\Attributes\Test;

class UrlEligibilityTest extends SurveyorTestCase
{
    #[Test]
    public function only_the_forums_own_scheme_host_and_port_are_on_site(): void
    {
        /** @var Site $site */
        $site = $this->make(Site::class);

        $this->assertSame('/d/1', $site->path('http://localhost/d/1'));
        $this->assertSame('/', $site->path('http://localhost'));
        $this->assertSame('/x', $site->path('http://LOCALHOST:80/a/../x'));

        foreach ([
            'https://localhost/d/1',          // another scheme
            'http://localhost:8080/d/1',      // another port
            'http://example.com/d/1',         // another host
            'http://127.0.0.1/d/1',           // an IP address that is not the forum's host
            'http://[::1]/d/1',
            'http://169.254.169.254/latest',  // cloud metadata
            'http://localhost@evil.test/d/1', // user info
            'http://user:pw@localhost/d/1',
            'ftp://localhost/d/1',
            '/relative',
        ] as $url) {
            $this->assertNull($site->path($url), $url);
        }
    }

    #[Test]
    public function a_forum_in_a_folder_only_owns_its_folder(): void
    {
        /** @var Site $site */
        $site = $this->make(Site::class);
        $site->setBase('https://example.com/forum');

        $this->assertSame('/d/1', $site->path('https://example.com/forum/d/1'));
        $this->assertSame('/', $site->path('https://example.com/forum'));
        $this->assertNull($site->path('https://example.com/forumx/d/1'));
        $this->assertNull($site->path('https://example.com/blog'));
        $this->assertNull($site->path('https://example.com/forum/../blog'));
        $this->assertSame('https://example.com/forum/d/1', $site->url('/d/1'));
        $this->assertSame('https://example.com/robots.txt', $site->robotsUrl());
        $this->assertTrue($site->fetchable('https://example.com/robots.txt'));
        $this->assertFalse($site->fetchable('https://example.com/other.txt'));

        // robots.txt rules are for the whole host, so the folder is part of the path matched.
        $robots = Robots::parse("User-agent: *\nDisallow: /forum/u/\n");
        /** @var Eligibility $eligibility */
        $eligibility = $this->make(Eligibility::class);
        $this->assertSame('robots', $eligibility->reason('/u/normal', $robots));
        $this->assertNull($eligibility->reason('/d/1', $robots));
    }

    #[Test]
    public function relative_links_resolve_like_a_browser(): void
    {
        /** @var Site $site */
        $site = $this->make(Site::class);
        $page = 'http://localhost/d/1-hello/2';

        $this->assertSame('http://localhost/t/roses', $site->resolve('/t/roses', $page));
        $this->assertSame('http://localhost/d/1-hello/3', $site->resolve('3', $page));
        $this->assertSame('http://localhost/d/2', $site->resolve('../2', $page));
        $this->assertSame('http://example.com/x', $site->resolve('//example.com/x', $page));
        $this->assertSame('http://localhost/d/1', $site->resolve('http://localhost/d/1#reply', $page));
        foreach (['mailto:a@b.c', 'javascript:alert(1)', '#top', '', 'data:text/html,x', "http://localhost/\nx"] as $href) {
            $this->assertNull($site->resolve($href, $page), $href);
        }
    }

    #[Test]
    public function private_routes_files_and_robots_disallowed_paths_are_never_crawled(): void
    {
        /** @var Eligibility $eligibility */
        $eligibility = $this->make(Eligibility::class);

        foreach (['/settings', '/notifications', '/admin', '/api/discussions', '/auth/github', '/confirm/abc', '/reset/abc', '/logout', '/login', '/register', '/messages', '/SETTINGS', '/%73ettings'] as $path) {
            $this->assertSame('denied', $eligibility->reason($path), $path);
        }

        foreach (['/assets/forum.js', '/storage/a.png', '/sitemap.xml', '/files/report.PDF'] as $path) {
            $this->assertNotNull($eligibility->reason($path), $path);
        }

        foreach (['/', '/d/1-hello', '/t/roses', '/u/normal', '/all', '/settingsx', '/tags'] as $path) {
            $this->assertNull($eligibility->reason($path), $path);
        }

        $robots = Robots::parse("User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow: /u/\nAllow: /u/public\nDisallow: /*?sort=\nDisallow: /d/*-draft$\n");
        $this->assertSame('robots', $eligibility->reason('/u/normal', $robots));
        $this->assertNull($eligibility->reason('/u/public', $robots));
        $this->assertNull($eligibility->reason('/d/1', $robots));
        $this->assertSame('robots', $eligibility->reason('/d/4-draft', $robots));
        $this->assertNull($eligibility->reason('/d/4-draft-two', $robots));

        // A group for Surveyor itself replaces the * group.
        $own = Robots::parse("User-agent: *\nDisallow: /\n\nUser-agent: Surveyor\nDisallow: /t/\n");
        $this->assertNull($eligibility->reason('/d/1', $own));
        $this->assertSame('robots', $eligibility->reason('/t/x', $own));

        $this->assertNull($eligibility->path('http://example.com/d/1'));
        $this->assertSame('/d/1', $eligibility->path('http://localhost/d/1?sort=new'));
    }

    #[Test]
    public function the_real_fetcher_refuses_anything_off_the_forum_before_any_request(): void
    {
        /** @var GuzzleFetcher $fetcher */
        $fetcher = $this->make(GuzzleFetcher::class);

        foreach (['http://example.com/', 'http://169.254.169.254/latest/meta-data', 'http://localhost:6379/', 'file:///etc/passwd'] as $url) {
            $page = $fetcher->get($url);
            $this->assertSame('off_host', $page->error, $url);
            $this->assertSame(0, $page->status);
        }

        $this->assertStringStartsWith('Surveyor/', GuzzleFetcher::userAgent($this->make(Site::class)));
        $this->assertStringContainsString('(+http://localhost)', GuzzleFetcher::userAgent($this->make(Site::class)));
    }
}
