<?php

namespace Ernestdefoe\Surveyor\Tests\integration;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Http\Fetcher;
use Ernestdefoe\Surveyor\IndexNow\Submitter;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;

abstract class SurveyorTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected const BASE = 'http://localhost';

    /** Everything Surveyor requires, then Surveyor. */
    protected const EXTENSIONS = ['flarum-tags', 'fof-seo', 'fof-sitemap', 'ernestdefoe-detour', 'ernestdefoe-surveyor'];

    protected FakeFetcher $fetcher;

    protected FakeSubmitter $submitter;

    protected FakeApiClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension(...static::EXTENSIONS);

        $now = Carbon::now()->subHour()->toDateTimeString();

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [
                ['id' => 1, 'title' => 'Welcome to the garden club', 'slug' => 'welcome-to-the-garden-club', 'created_at' => $now, 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 2, 'last_post_number' => 2],
                ['id' => 2, 'title' => 'Secret garden plans', 'slug' => 'secret-garden-plans', 'created_at' => $now, 'user_id' => 2, 'first_post_id' => 3, 'comment_count' => 1, 'is_private' => true, 'last_post_number' => 1],
                ['id' => 3, 'title' => 'An old hidden thread', 'slug' => 'an-old-hidden-thread', 'created_at' => $now, 'user_id' => 2, 'first_post_id' => 4, 'comment_count' => 1, 'hidden_at' => $now, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'created_at' => $now, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Hello</p></t>', 'number' => 1],
                ['id' => 2, 'discussion_id' => 1, 'created_at' => $now, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Reply</p></t>', 'number' => 2],
                ['id' => 3, 'discussion_id' => 2, 'created_at' => $now, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Private</p></t>', 'number' => 1, 'is_private' => true],
                ['id' => 4, 'discussion_id' => 3, 'created_at' => $now, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Old</p></t>', 'number' => 1],
            ],
        ]);
    }

    /** Swaps in the fake fetcher and IndexNow submitter. Call after anything that boots the app is set up. */
    protected function fakes(): void
    {
        $container = $this->app()->getContainer();
        $this->fetcher = new FakeFetcher();
        $this->submitter = new FakeSubmitter();
        $this->http = new FakeApiClient();
        $container->instance(Fetcher::class, $this->fetcher);
        $container->instance(Submitter::class, $this->submitter);
        $container->instance(\Ernestdefoe\Surveyor\Http\ApiClient::class, $this->http);
    }

    protected function make(string $class): mixed
    {
        return $this->app()->getContainer()->make($class);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     */
    protected function api(string $method, string $path, ?array $json = null, ?int $as = 1, array $query = []): ResponseInterface
    {
        $options = $as === null ? [] : ['authenticatedAs' => $as];
        if ($json !== null) {
            $options['json'] = $json;
        }

        $request = $this->request($method, '/api/surveyor'.$path, $options);

        if ($query) {
            $request = $request->withQueryParams($query);
        }

        return $this->send($request);
    }

    /** @return array<string, mixed> */
    protected function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /** A plain forum page as FoF SEO and Flarum would render it for a guest. */
    protected static function html(string $title, string $body, string $canonical, string $description = 'A friendly forum page about gardening, roses and everything that grows in spring.', string $extraHead = ''): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><title>'.$title.'</title>'
            .'<meta name="description" content="'.$description.'">'
            .'<link rel="canonical" href="'.$canonical.'">'
            .'<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage"}</script>'
            .$extraHead
            .'</head><body><div id="app"><a href="'.self::BASE.'" id="home-link">Forum</a>'
            .'<noscript id="flarum-content"><div class="container"><h1>'.$title.'</h1>'.$body.'</div></noscript></div></body></html>';
    }

    protected static function words(int $n): string
    {
        return '<p>'.implode(' ', array_fill(0, $n, 'garden')).'</p>';
    }
}
