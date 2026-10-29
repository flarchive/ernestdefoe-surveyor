<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Google\Google;
use Ernestdefoe\Surveyor\Google\Inspector;
use Ernestdefoe\Surveyor\Google\Jwt;
use Ernestdefoe\Surveyor\Google\SearchSync;
use Ernestdefoe\Surveyor\Google\ServiceAccount;
use Ernestdefoe\Surveyor\Settings;
use PHPUnit\Framework\Attributes\Test;

class GoogleTest extends SurveyorTestCase
{
    private const TOKEN = 'oauth2.googleapis.com/token';
    private const ANALYTICS = '/searchAnalytics/query';

    /** A real (throwaway) service account key, made once per process. @return array{json: string, public: string} */
    public static function account(): array
    {
        static $made = null;

        if ($made === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            $made = [
                'json' => (string) json_encode([
                    'type' => 'service_account',
                    'project_id' => 'test-project',
                    'private_key_id' => 'abc',
                    'private_key' => $pem,
                    'client_email' => 'surveyor@test-project.iam.gserviceaccount.com',
                    'token_uri' => 'https://oauth2.googleapis.com/token',
                ]),
                'public' => openssl_pkey_get_details($key)['key'],
            ];
        }

        return $made;
    }

    private function connect(): void
    {
        $this->fakes();
        $this->http->on(self::TOKEN, ['status' => 200, 'body' => ['access_token' => 'ya29.token', 'expires_in' => 3600, 'token_type' => 'Bearer']]);
        $response = $this->api('POST', '/search/connect', ['key' => self::account()['json'], 'property' => 'sc-domain:localhost']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertTrue($this->json($response)['tokenOk']);
    }

    /** @param list<array{0: string, 1: int, 2: int, 3: float}> $rows [key, clicks, impressions, position] */
    private static function rows(array $rows): array
    {
        return ['status' => 200, 'body' => ['rows' => array_map(fn ($r) => ['keys' => (array) $r[0], 'clicks' => $r[1], 'impressions' => $r[2], 'ctr' => $r[2] ? $r[1] / $r[2] : 0, 'position' => $r[3]], $rows)]];
    }

    #[Test]
    public function the_jwt_is_signed_with_the_service_accounts_key(): void
    {
        $account = ServiceAccount::parse(self::account()['json']);
        $this->assertInstanceOf(ServiceAccount::class, $account);

        $jwt = Jwt::assertion($account, Google::READ, 1_800_000_000);
        [$header, $claims, $signature] = explode('.', $jwt);
        $decode = fn ($part) => json_decode((string) base64_decode(strtr($part, '-_', '+/')), true);

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $decode($header));
        $this->assertSame([
            'iss' => 'surveyor@test-project.iam.gserviceaccount.com',
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => 1_800_000_000,
            'exp' => 1_800_003_600,
        ], $decode($claims));
        $this->assertSame(1, openssl_verify("$header.$claims", (string) base64_decode(strtr($signature, '-_', '+/').str_repeat('=', (4 - strlen($signature) % 4) % 4)), self::account()['public'], OPENSSL_ALGO_SHA256));
    }

    #[Test]
    public function bad_keys_and_properties_are_refused(): void
    {
        $this->assertSame('not_service_account', ServiceAccount::parse('{"type":"authorized_user"}'));
        $this->assertSame('not_service_account', ServiceAccount::parse('not json'));
        $bad = json_decode(self::account()['json'], true);
        $this->assertSame('token_uri', ServiceAccount::parse((string) json_encode(['token_uri' => 'https://evil.example/token'] + $bad)));
        $this->assertSame('private_key', ServiceAccount::parse((string) json_encode(['private_key' => 'nope'] + $bad)));

        $this->fakes();
        $this->assertSame(422, $this->api('POST', '/search/connect', ['key' => '{"type":"x"}', 'property' => 'sc-domain:localhost'])->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/search/connect', ['key' => self::account()['json'], 'property' => 'sc-domain:example.com'])->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/search/connect', ['key' => self::account()['json'], 'property' => 'http://localhost'])->getStatusCode());
        $this->assertSame([], $this->http->calls, 'nothing sent to Google for a refused key');

        /** @var SearchSync $sync */
        $sync = $this->make(SearchSync::class);
        $this->assertTrue($sync->propertyValid('sc-domain:localhost'));
        $this->assertTrue($sync->propertyValid('http://localhost/'));
        $this->assertFalse($sync->propertyValid('https://example.com/'));
    }

    #[Test]
    public function the_access_token_is_cached_and_reused(): void
    {
        $this->connect();
        $this->http->on(self::ANALYTICS, self::rows([]));

        $this->make(SearchSync::class)->refresh();
        $this->make(SearchSync::class)->refresh();

        // One exchange at connect; every later call uses the cached token.
        $this->assertCount(1, $this->http->to(self::TOKEN));
        $request = $this->http->to(self::TOKEN)[0];
        parse_str((string) $request['body'], $form);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);

        foreach ($this->http->to(self::ANALYTICS) as $call) {
            $this->assertSame('Bearer ya29.token', $call['headers']['Authorization']);
            $this->assertStringContainsString('/sites/sc-domain%3Alocalhost/', $call['url']);
        }
    }

    #[Test]
    public function a_refresh_stores_pages_queries_days_and_history(): void
    {
        $this->connect();
        $end = Carbon::now()->startOfDay()->subDays(3);

        $this->http->on(self::ANALYTICS, [
            // pages, last 28 days (an http and an https variant of one page merge; another host is ignored)
            self::rows([['http://localhost/d/1-welcome', 30, 1000, 4.0], ['http://localhost/t/roses', 2, 400, 12.0], ['https://example.com/d/9', 50, 50, 1.0]]),
            // pages, the 28 days before
            self::rows([['http://localhost/d/1-welcome', 60, 1200, 3.0]]),
            // page + query
            self::rows([[['http://localhost/d/1-welcome', 'garden club'], 20, 600, 3.5], [['http://localhost/d/1-welcome', 'roses'], 10, 400, 4.5]]),
            // days
            self::rows([[$end->toDateString(), 3, 100, 5.0]]),
        ]);

        $this->assertNull($this->make(SearchSync::class)->refresh());

        $page = $this->database()->table('surveyor_search_pages')->where('path', '/d/1-welcome')->first();
        $this->assertSame(30, (int) $page->clicks);
        $this->assertSame(60, (int) $page->prev_clicks);
        $this->assertSame(400, (int) $page->position);
        $this->assertSame(2, $this->database()->table('surveyor_search_pages')->count());
        $this->assertSame(2, $this->database()->table('surveyor_search_queries')->where('url_hash', sha1('/d/1-welcome'))->count());
        $this->assertSame(1, $this->database()->table('surveyor_search_days')->count());
        $this->assertSame(2, $this->database()->table('surveyor_search_history')->count());

        $body = json_decode($this->http->to(self::ANALYTICS)[0]['body'], true);
        $this->assertSame(['page'], $body['dimensions']);
        $this->assertSame($end->toDateString(), $body['endDate']);
        $this->assertSame($end->copy()->subDays(27)->toDateString(), $body['startDate']);

        $pages = $this->json($this->api('GET', '/search/pages'));
        $this->assertSame('/d/1-welcome', $pages['data'][0]['path']);
        $this->assertEquals(3, $pages['data'][0]['ctr']);
        $queries = $this->json($this->api('GET', '/search/queries', query: ['path' => '/d/1-welcome']));
        $this->assertSame('garden club', $queries['data'][0]['query']);
    }

    #[Test]
    public function an_error_pauses_every_google_call_and_keeps_the_old_data(): void
    {
        $this->connect();
        $this->database()->table('surveyor_search_pages')->insert(['url_hash' => sha1('/x'), 'path' => '/x', 'clicks' => 5, 'impressions' => 5, 'period_end' => '2026-01-01', 'updated_at' => Carbon::now()]);
        $this->http->on(self::ANALYTICS, ['status' => 429, 'body' => ['error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']], 'headers' => ['retry-after' => '120']]);

        $this->assertSame('Quota exceeded', $this->make(SearchSync::class)->refresh());
        $this->assertSame(1, $this->database()->table('surveyor_search_pages')->count(), 'old data kept');

        $calls = count($this->http->calls);
        $this->assertSame('paused', $this->make(SearchSync::class)->refresh());
        $this->assertCount($calls, $this->http->calls, 'no call while paused');

        $this->assertSame('warning', array_column($this->json($this->api('GET', '/diagnostics'))['data'], null, 'id')['google']['level']);
    }

    #[Test]
    public function url_inspection_is_capped_per_day_and_per_minute(): void
    {
        $this->connect();
        $this->setting(Settings::key('inspect_daily'), '2');
        $this->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set(Settings::key('inspect_daily'), '2');
        $this->http->on('urlInspection', ['status' => 200, 'body' => ['inspectionResult' => [
            'indexStatusResult' => ['verdict' => 'PASS', 'coverageState' => 'Submitted and indexed', 'lastCrawlTime' => '2026-10-20T10:00:00Z', 'googleCanonical' => 'http://localhost/d/1-welcome', 'robotsTxtState' => 'ALLOWED', 'indexingState' => 'INDEXING_ALLOWED', 'pageFetchState' => 'SUCCESSFUL'],
            'richResultsResult' => ['verdict' => 'PASS'],
        ]]]);

        $first = $this->json($this->api('POST', '/search/inspect', ['path' => '/d/1-welcome']));
        $this->assertNull($first['error']);
        $this->assertSame('PASS', $first['data']['verdict']);
        $this->assertSame('Submitted and indexed', $first['data']['coverage']);
        $this->assertSame('PASS', $first['data']['richVerdict']);

        $body = json_decode($this->http->to('urlInspection')[0]['body'], true);
        $this->assertSame(['inspectionUrl' => 'http://localhost/d/1-welcome', 'siteUrl' => 'sc-domain:localhost'], $body);

        $this->api('POST', '/search/inspect', ['path' => '/t/roses']);
        $third = $this->json($this->api('POST', '/search/inspect', ['path' => '/']));
        $this->assertSame('daily_cap', $third['error']);
        $this->assertCount(2, $this->http->to('urlInspection'));

        $this->assertSame(Inspector::PER_MINUTE, 10);
    }

    #[Test]
    public function the_sitemap_is_submitted_with_the_write_scope(): void
    {
        $this->connect();
        $this->http->on('/sitemaps/', ['status' => 204, 'body' => '']);
        $this->http->on('/sitemaps', ['status' => 200, 'body' => ['sitemap' => [['path' => 'http://localhost/sitemap.xml', 'lastSubmitted' => '2026-10-29T10:00:00Z', 'isPending' => false, 'errors' => 0, 'warnings' => 1]]]]);

        $result = $this->json($this->api('POST', '/search/sitemaps'));
        $this->assertNull($result['error']);
        $this->assertSame(1, $result['data'][0]['warnings']);

        $put = array_values(array_filter($this->http->calls, fn ($c) => $c['method'] === 'PUT'))[0];
        $this->assertStringEndsWith('/sites/sc-domain%3Alocalhost/sitemaps/http%3A%2F%2Flocalhost%2Fsitemap.xml', $put['url']);

        // A second token, for the read-write scope.
        $tokens = $this->http->to(self::TOKEN);
        $this->assertCount(2, $tokens);
        parse_str((string) $tokens[1]['body'], $form);
        $claims = json_decode((string) base64_decode(strtr(explode('.', $form['assertion'])[1], '-_', '+/')), true);
        $this->assertSame(Google::WRITE, $claims['scope']);
    }
}
