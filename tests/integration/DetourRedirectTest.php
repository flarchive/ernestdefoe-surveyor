<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Carbon\Carbon;
use Ernestdefoe\Detour\Redirect;
use PHPUnit\Framework\Attributes\Test;

class DetourRedirectTest extends SurveyorTestCase
{
    #[Test]
    public function a_redirect_is_made_by_detour_and_works(): void
    {
        $response = $this->api('POST', '/redirects', ['source' => '/d/99-gone', 'target' => '/d/1', 'status' => 301]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $redirect = Redirect::query()->first();
        $this->assertSame('exact', $redirect->type);
        $this->assertSame('/d/99-gone', $redirect->source);
        $this->assertSame('/d/1', $redirect->target);
        $this->assertSame('missing', $redirect->mode);
        $this->assertSame('Surveyor', $redirect->note);

        // Detour itself now answers the old address.
        $old = $this->send($this->request('GET', '/d/99-gone')->withHeader('Accept', 'text/html'));
        $this->assertSame(301, $old->getStatusCode());
        $this->assertStringEndsWith('/d/1', $old->getHeaderLine('Location'));
    }

    #[Test]
    public function detours_own_checks_refuse_what_it_would_refuse(): void
    {
        foreach ([
            ['source' => '/', 'target' => '/d/1'],                       // the home page
            ['source' => '/old', 'target' => 'https://example.com/'],    // another host
            ['source' => '/old', 'target' => 'javascript:alert(1)'],
            ['source' => '/admin', 'target' => '/d/1'],                  // reserved
            ['source' => '/loop', 'target' => '/loop'],                  // a loop
        ] as $body) {
            $response = $this->api('POST', '/redirects', $body + ['status' => 301]);
            $this->assertSame(422, $response->getStatusCode(), json_encode($body));
        }

        $this->assertSame(0, Redirect::query()->count());
    }

    #[Test]
    public function detours_404_log_is_listed(): void
    {
        $now = Carbon::now()->toDateTimeString();
        $this->prepareDatabase(['detour_missing' => [
            ['id' => 1, 'url_hash' => sha1('/wp-login.php'), 'url' => '/wp-login.php', 'hits' => 3, 'first_seen_at' => $now, 'last_seen_at' => $now, 'ignored' => false],
            ['id' => 2, 'url_hash' => sha1('/old-thread'), 'url' => '/old-thread', 'hits' => 40, 'first_seen_at' => $now, 'last_seen_at' => $now, 'ignored' => false],
            ['id' => 3, 'url_hash' => sha1('/ignored'), 'url' => '/ignored', 'hits' => 99, 'first_seen_at' => $now, 'last_seen_at' => $now, 'ignored' => true],
        ]]);

        $rows = $this->json($this->api('GET', '/missing'))['data'];
        $this->assertSame(['/old-thread', '/wp-login.php'], array_column($rows, 'url'));
    }
}
