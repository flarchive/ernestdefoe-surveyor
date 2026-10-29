<?php

namespace Ernestdefoe\Surveyor\Api;

use Ernestdefoe\Surveyor\IndexNow\Sender;
use Ernestdefoe\Surveyor\Settings;
use Ernestdefoe\Surveyor\Site;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

class IndexNowController extends Controller
{
    protected array $actions = ['show', 'enable', 'disable', 'key', 'submit'];

    public function __construct(
        protected ConnectionInterface $db,
        protected Settings $settings,
        protected Sender $sender,
        protected Site $site,
    ) {
    }

    /** @return array<string, mixed> */
    protected function show(ServerRequestInterface $request): array
    {
        $key = $this->settings->indexNowKey();

        return [
            'enabled' => $this->settings->indexNowActive(),
            'key' => $key,
            'keyUrl' => $key ? $this->site->url('/'.$key.'.txt') : null,
            'pending' => $this->db->table('surveyor_indexnow_queue')->where('pending', true)->count(),
            'backoffUntil' => ($until = $this->sender->backoffUntil()) ? date(DATE_ATOM, $until) : null,
            'log' => $this->db->table('surveyor_indexnow_log')->orderByDesc('id')->limit(20)->get()->map(fn ($r) => [
                'id' => (int) $r->id,
                'origin' => $r->origin,
                'count' => (int) $r->url_count,
                'status' => $r->status === null ? null : (int) $r->status,
                'message' => $r->message,
                'sample' => json_decode((string) $r->sample, true) ?: [],
                'at' => self::time($r->created_at),
            ])->all(),
        ];
    }

    /** Turns IndexNow on, making a key the first time. @return array<string, mixed> */
    protected function enable(ServerRequestInterface $request): array
    {
        if ($this->settings->indexNowKey() === null) {
            $this->settings->set('indexnow_key', bin2hex(random_bytes(16)));
        }
        $this->settings->set('indexnow_enabled', '1');

        return $this->show($request);
    }

    /** @return array<string, mixed> */
    protected function disable(ServerRequestInterface $request): array
    {
        $this->settings->set('indexnow_enabled', '0');

        return $this->show($request);
    }

    /** A new key (the old file stops answering at once). @return array<string, mixed> */
    protected function key(ServerRequestInterface $request): array
    {
        $this->settings->set('indexnow_key', bin2hex(random_bytes(16)));

        return $this->show($request);
    }

    /** @return array<string, mixed> */
    protected function submit(ServerRequestInterface $request): array
    {
        if (! $this->settings->indexNowActive()) {
            return ['status' => 0, 'sent' => [], 'refused' => [], 'off' => true];
        }

        $urls = $this->body($request)['urls'] ?? [];
        $urls = is_string($urls) ? preg_split('/\R/', $urls) : (array) $urls;

        return $this->sender->sendManual(array_values(array_filter(array_map('strval', $urls ?: []))));
    }
}
