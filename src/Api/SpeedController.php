<?php

namespace Ernestdefoe\Surveyor\Api;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Http\ApiLog;
use Ernestdefoe\Surveyor\Security\Secrets;
use Ernestdefoe\Surveyor\Site;
use Ernestdefoe\Surveyor\Speed\PageSpeed;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

class SpeedController extends Controller
{
    protected array $actions = ['show', 'queue', 'key', 'history'];

    public function __construct(
        protected ConnectionInterface $db,
        protected PageSpeed $speed,
        protected Secrets $secrets,
        protected ApiLog $log,
        protected Site $site,
    ) {
    }

    /** @return array<string, mixed> */
    protected function show(ServerRequestInterface $request): array
    {
        // The newest measurement of every page and strategy.
        $latest = $this->db->table('surveyor_speed')
            ->whereIn('id', fn ($q) => $q->from('surveyor_speed')->groupBy('url_hash', 'strategy')->selectRaw('MAX(id)'))
            ->orderBy('path')->orderBy('strategy')
            ->limit(400)
            ->get();

        return [
            'hasKey' => $this->secrets->has(Secrets::PAGESPEED),
            'today' => $this->log->today('pagespeed'),
            'cap' => $this->speed->dailyCap(),
            'pausedUntil' => ($until = $this->log->pausedUntil('pagespeed')) ? date(DATE_ATOM, $until) : null,
            'queue' => $this->db->table('surveyor_speed_queue')->orderBy('queued_at')->limit(100)->get(['path', 'strategy'])->map(fn ($q) => ['path' => $q->path, 'strategy' => $q->strategy])->all(),
            'data' => $latest->map(fn ($r) => self::row($r, $this->site))->all(),
        ];
    }

    /** @return array<string, mixed> */
    protected function queue(ServerRequestInterface $request): array
    {
        $body = $this->body($request);
        $path = (string) ($body['path'] ?? '');
        $path = preg_match('~^https?://~i', $path) ? (string) $this->site->path($path) : $path;
        $strategy = in_array($body['strategy'] ?? null, PageSpeed::STRATEGIES, true) ? [$body['strategy']] : null;

        $queued = $path !== '' && $path[0] === '/' && strlen($path) <= 1000 ? $this->speed->queue($path, $strategy) : 0;

        return ['queued' => $queued] + $this->show($request);
    }

    /** Saves (or, empty, removes) the optional PageSpeed API key. @return array<string, mixed> */
    protected function key(ServerRequestInterface $request): array
    {
        $key = trim((string) ($this->body($request)['key'] ?? ''));
        $rejected = false;

        if ($key === '') {
            $this->secrets->forget(Secrets::PAGESPEED);
        } elseif (preg_match('/^[A-Za-z0-9_-]{20,100}$/', $key)) {
            $this->secrets->put(Secrets::PAGESPEED, $key);
            $this->log->resume('pagespeed');
        } else {
            $rejected = true;
        }

        return ['keyRejected' => $rejected] + $this->show($request);
    }

    /** @return array<string, mixed> */
    protected function history(ServerRequestInterface $request): array
    {
        $path = (string) $this->query($request, 'path', '');

        return ['data' => $this->db->table('surveyor_speed')->where('url_hash', sha1($path))->orderByDesc('id')->limit(40)->get()
            ->map(fn ($r) => self::row($r, $this->site))->all()];
    }

    /** @return array<string, mixed> */
    public static function row(object $r, Site $site): array
    {
        return [
            'id' => (int) $r->id,
            'path' => $r->path,
            'url' => $site->url($r->path),
            'strategy' => $r->strategy,
            'score' => $r->score === null ? null : (int) $r->score,
            'lcp' => $r->lcp_ms === null ? null : (int) $r->lcp_ms,
            'fcp' => $r->fcp_ms === null ? null : (int) $r->fcp_ms,
            'tbt' => $r->tbt_ms === null ? null : (int) $r->tbt_ms,
            'cls' => $r->cls === null ? null : $r->cls / 1000,
            'fieldLcp' => $r->field_lcp_ms === null ? null : (int) $r->field_lcp_ms,
            'fieldInp' => $r->field_inp_ms === null ? null : (int) $r->field_inp_ms,
            'fieldCls' => $r->field_cls === null ? null : $r->field_cls / 1000,
            'fieldCategory' => $r->field_category,
            'fieldScope' => $r->field_scope,
            'error' => $r->error,
            'measuredAt' => Carbon::parse($r->measured_at)->toIso8601String(),
        ];
    }
}
