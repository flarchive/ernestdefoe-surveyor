<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class AdminAccessTest extends SurveyorTestCase
{
    /** Every route in extend.php. */
    public static function endpoints(): array
    {
        return [
            ['GET', '/summary'], ['GET', '/audit'], ['POST', '/audit/start'], ['POST', '/audit/stop'], ['POST', '/audit/step'],
            ['GET', '/pages'], ['GET', '/pages/1'], ['GET', '/duplicates'],
            ['GET', '/links'], ['GET', '/links/1/sources'], ['POST', '/links/1/recheck'], ['PATCH', '/links/1'],
            ['POST', '/redirects'], ['GET', '/missing'],
            ['GET', '/indexnow'], ['POST', '/indexnow/enable'], ['POST', '/indexnow/disable'], ['POST', '/indexnow/key'], ['POST', '/indexnow/submit'],
            ['GET', '/rules'], ['POST', '/rules'], ['PATCH', '/rules/1'], ['DELETE', '/rules/1'], ['POST', '/rules/test'],
            ['GET', '/diagnostics'],
            ['GET', '/search'], ['POST', '/search/connect'], ['POST', '/search/disconnect'], ['POST', '/search/refresh'], ['GET', '/search/pages'],
            ['GET', '/search/queries'], ['POST', '/search/inspect'], ['GET', '/search/sitemaps'], ['POST', '/search/sitemaps'],
            ['GET', '/opportunities'], ['GET', '/speed'], ['POST', '/speed'], ['POST', '/speed/key'], ['GET', '/speed/history'],
            ['GET', '/regressions'], ['GET', '/internal-links'], ['GET', '/related'],
            ['GET', '/ai'], ['POST', '/ai/key'], ['GET', '/ai/candidates'], ['POST', '/ai/preview'], ['POST', '/ai/apply'], ['POST', '/ai/undo'],
        ];
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function guests_and_members_are_refused(string $method, string $path): void
    {
        $this->fakes();

        $body = in_array($method, ['POST', 'PATCH'], true) ? [] : null;

        // A guest's write is stopped even earlier, by Flarum's CSRF check (400).
        $this->assertContains($this->api($method, $path, $body, null)->getStatusCode(), $method === 'GET' ? [401, 403] : [400, 401, 403], "$method $path as guest");
        $this->assertSame(403, $this->api($method, $path, $body, 2)->getStatusCode(), "$method $path as a member");

        // Nothing was fetched, started or changed on their behalf.
        $this->assertSame([], $this->fetcher->requested);
        $this->assertSame(0, $this->database()->table('surveyor_runs')->count());
        $this->assertNull($this->database()->table('settings')->where('key', 'ernestdefoe-surveyor.indexnow_key')->value('value'));
        $this->assertSame([], $this->http->calls);
        $this->assertSame(0, $this->database()->table('surveyor_secrets')->count());
    }

    #[Test]
    public function an_admin_gets_the_dashboard_and_diagnostics(): void
    {
        $this->fakes();
        $this->fetcher->text('http://localhost/robots.txt', "User-agent: *\nDisallow:\nSitemap: http://localhost/sitemap.xml\n");
        $this->fetcher->text('http://localhost/sitemap.xml', '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>http://localhost/d/1</loc></url></urlset>', 200, 'application/xml');
        $this->fetcher->html('http://localhost/', self::html('Home', '', 'http://localhost'));

        $this->assertSame(200, $this->api('GET', '/summary')->getStatusCode());

        $checks = array_column($this->json($this->api('GET', '/diagnostics'))['data'], null, 'id');
        $this->assertSame('pass', $checks['sitemap']['level']);
        $this->assertSame('pass', $checks['robots']['level']);
        $this->assertSame('pass', $checks['self_fetch']['level']);
        $this->assertSame('pass', $checks['canonical_home']['level']);
        $this->assertSame('pass', $checks['tables']['level']);
        $this->assertSame('pass', $checks['conflicts']['level']);
        $this->assertSame('info', $checks['indexnow']['level']);
        $this->assertSame('warning', $checks['scheduler']['level']);
    }
}
