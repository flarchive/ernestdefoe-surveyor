<?php

namespace Ernestdefoe\Surveyor\Api;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Google\Google;
use Ernestdefoe\Surveyor\Google\Inspector;
use Ernestdefoe\Surveyor\Google\SearchSync;
use Ernestdefoe\Surveyor\Google\ServiceAccount;
use Ernestdefoe\Surveyor\Google\Sitemaps;
use Ernestdefoe\Surveyor\Http\ApiLog;
use Ernestdefoe\Surveyor\Security\Secrets;
use Ernestdefoe\Surveyor\Settings;
use Ernestdefoe\Surveyor\Site;
use Flarum\Foundation\ValidationException;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Search Console: connect, refresh, pages and queries, URL Inspection, sitemaps. */
class SearchController extends Controller
{
    public const PER_PAGE = 50;

    protected array $actions = ['show', 'connect', 'disconnect', 'refresh', 'pages', 'queries', 'inspect', 'sitemaps', 'submit'];

    public function __construct(
        protected ConnectionInterface $db,
        protected Secrets $secrets,
        protected Settings $settings,
        protected Google $google,
        protected SearchSync $sync,
        protected Inspector $inspector,
        protected Sitemaps $sitemaps,
        protected ApiLog $log,
        protected Site $site,
        protected TranslatorInterface $translator,
    ) {
    }

    /** @return array<string, mixed> */
    protected function show(ServerRequestInterface $request): array
    {
        $property = $this->settings->get('google_property');
        $totals = $this->db->table('surveyor_search_pages')
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(prev_clicks) as prev_clicks, SUM(prev_impressions) as prev_impressions, COUNT(*) as pages')
            ->first();
        $refreshed = (int) $this->settings->get('google_refreshed_at');

        return [
            // Never the key itself: whether one is stored, and the account's email.
            'connected' => $this->secrets->has(Secrets::GOOGLE),
            'email' => $this->secrets->label(Secrets::GOOGLE),
            'property' => $property,
            'propertyValid' => $property !== '' && $this->sync->propertyValid($property),
            'refreshedAt' => $refreshed ? date(DATE_ATOM, $refreshed) : null,
            'pausedUntil' => ($until = $this->log->pausedUntil('google')) ? date(DATE_ATOM, $until) : null,
            'lastError' => $this->db->table('surveyor_api_log')->where('service', 'google')->whereNotNull('message')->orderByDesc('id')->value('message'),
            'inspectionsToday' => $this->log->today('google', 'inspect'),
            'inspectionCap' => $this->inspector->dailyCap(),
            'sitemapUrl' => $this->sitemaps->sitemapUrl(),
            'totals' => [
                'pages' => (int) ($totals->pages ?? 0),
                'clicks' => (int) ($totals->clicks ?? 0),
                'impressions' => (int) ($totals->impressions ?? 0),
                'prevClicks' => (int) ($totals->prev_clicks ?? 0),
                'prevImpressions' => (int) ($totals->prev_impressions ?? 0),
            ],
            'days' => $this->db->table('surveyor_search_days')->orderByDesc('day')->limit(90)->get()->reverse()->values()->map(fn ($d) => [
                'day' => (string) $d->day,
                'clicks' => (int) $d->clicks,
                'impressions' => (int) $d->impressions,
            ])->all(),
        ];
    }

    /** Saves the key (encrypted) and the property, then checks a token can be had. @return array<string, mixed> */
    protected function connect(ServerRequestInterface $request): array
    {
        $body = $this->body($request);
        $json = trim((string) ($body['key'] ?? ''));
        $property = trim((string) ($body['property'] ?? $this->settings->get('google_property')));

        if ($json !== '') {
            $account = ServiceAccount::parse($json);
            if (! $account instanceof ServiceAccount) {
                $this->fail('key', 'google_key_'.$account);
            }
            $this->secrets->put(Secrets::GOOGLE, $json, $account->email);
        } elseif (! $this->secrets->has(Secrets::GOOGLE)) {
            $this->fail('key', 'google_key_not_service_account');
        }

        if (! $this->sync->propertyValid($property)) {
            $this->fail('property', 'google_property_invalid');
        }
        $this->settings->set('google_property', $property);

        $this->google->forgetTokens();
        $ok = $this->google->token() !== null;

        return ['tokenOk' => $ok] + $this->show($request);
    }

    /** @return array<string, mixed> */
    protected function disconnect(ServerRequestInterface $request): array
    {
        $this->secrets->forget(Secrets::GOOGLE);
        $this->google->forgetTokens();

        return $this->show($request);
    }

    /** @return array<string, mixed> */
    protected function refresh(ServerRequestInterface $request): array
    {
        // A handful of requests to Google, each with a 30 second limit: room for them in this request.
        @set_time_limit(180);

        return ['error' => $this->sync->refresh()] + $this->show($request);
    }

    /** @return array<string, mixed> */
    protected function pages(ServerRequestInterface $request): array
    {
        $query = $this->db->table('surveyor_search_pages');
        $q = trim((string) $this->query($request, 'q', ''));
        if ($q !== '') {
            $query->where('path', 'like', '%'.addcslashes(mb_substr($q, 0, 200), '%_\\').'%');
        }

        $total = (clone $query)->count();

        match ((string) $this->query($request, 'sort', '')) {
            'impressions' => $query->orderByDesc('impressions'),
            'position' => $query->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')->orderBy('position'),
            'change' => $query->orderByRaw('(clicks - prev_clicks) ASC'),
            default => $query->orderByDesc('clicks'),
        };

        $rows = $query->orderBy('id')->offset($this->offset($request))->limit(self::PER_PAGE)->get();
        $hashes = $rows->pluck('url_hash')->all();

        $inspections = $this->db->table('surveyor_inspections')->whereIn('url_hash', $hashes)->get()->keyBy('url_hash');
        $audit = $this->db->table('surveyor_pages')->whereIn('url_hash', $hashes)->get(['url_hash', 'id', 'score'])->keyBy('url_hash');

        return [
            'data' => $rows->map(fn ($r) => self::page($r, $this->site) + [
                'inspection' => ($i = $inspections->get($r->url_hash)) ? self::inspection($i) : null,
                'auditId' => ($a = $audit->get($r->url_hash)) ? (int) $a->id : null,
                'auditScore' => $a && $a->score !== null ? (int) $a->score : null,
            ])->all(),
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    protected function queries(ServerRequestInterface $request): array
    {
        $path = (string) $this->query($request, 'path', '');
        $history = $this->db->table('surveyor_search_history')->where('url_hash', sha1($path))->orderBy('week')->get();

        return [
            'data' => $this->db->table('surveyor_search_queries')->where('url_hash', sha1($path))->orderByDesc('impressions')->limit(20)->get()
                ->map(fn ($r) => self::metrics($r) + ['query' => $r->query])->all(),
            'history' => $history->map(fn ($h) => ['week' => (string) $h->week] + self::metrics($h))->all(),
        ];
    }

    /** @return array<string, mixed> */
    protected function inspect(ServerRequestInterface $request): array
    {
        $path = (string) ($this->body($request)['path'] ?? '');
        $path = preg_match('~^https?://~i', $path) ? (string) $this->site->path($path) : $path;

        if ($path === '' || $path[0] !== '/') {
            $this->fail('path', 'google_path_invalid');
        }

        $result = $this->inspector->inspect($path);

        return ['error' => $result['error'], 'data' => $result['row'] ? self::inspection($result['row']) : null];
    }

    /** @return array<string, mixed> */
    protected function sitemaps(ServerRequestInterface $request): array
    {
        return $this->sitemaps->list();
    }

    /** @return array<string, mixed> */
    protected function submit(ServerRequestInterface $request): array
    {
        return ['error' => $this->sitemaps->submit()] + $this->sitemaps->list();
    }

    /** @return array<string, mixed> */
    public static function page(object $r, Site $site): array
    {
        return ['id' => (int) $r->id, 'path' => $r->path, 'url' => $site->url($r->path)] + self::metrics($r) + [
            'prevClicks' => (int) $r->prev_clicks,
            'prevImpressions' => (int) $r->prev_impressions,
            'prevPosition' => $r->prev_position === null ? null : round($r->prev_position / 100, 1),
        ];
    }

    /** @return array<string, mixed> */
    public static function metrics(object $r): array
    {
        $impressions = (int) $r->impressions;

        return [
            'clicks' => (int) $r->clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? round($r->clicks / $impressions * 100, 1) : 0.0,
            'position' => $r->position === null ? null : round($r->position / 100, 1),
        ];
    }

    /** @return array<string, mixed> */
    public static function inspection(object $i): array
    {
        return [
            'verdict' => $i->verdict,
            'coverage' => $i->coverage,
            'indexingState' => $i->indexing_state,
            'fetchState' => $i->fetch_state,
            'robotsState' => $i->robots_state,
            'googleCanonical' => $i->google_canonical,
            'richVerdict' => $i->rich_verdict,
            'lastCrawlAt' => $i->last_crawl_at ? Carbon::parse($i->last_crawl_at)->toIso8601String() : null,
            'error' => $i->error,
            'inspectedAt' => Carbon::parse($i->inspected_at)->toIso8601String(),
        ];
    }

    private function fail(string $field, string $key): never
    {
        throw new ValidationException([$field => $this->translator->trans('ernestdefoe-surveyor.api.'.$key)]);
    }
}
