<?php

namespace Ernestdefoe\Surveyor\Http;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * A short log of outside calls, and the pauses after failures: a service
 * that refused or failed is not asked again until its pause ends.
 */
class ApiLog
{
    public const ROWS = 500;

    public function __construct(
        protected ConnectionInterface $db,
        protected Cache $cache,
    ) {
    }

    public function record(string $service, string $action, int $status, ?string $message = null, ?int $in = null, ?int $out = null): void
    {
        $this->db->table('surveyor_api_log')->insert([
            'service' => $service,
            'action' => $action,
            'status' => $status ?: null,
            'message' => $message === null ? null : mb_substr($message, 0, 255),
            'input_tokens' => $in,
            'output_tokens' => $out,
            'created_at' => Carbon::now(),
        ]);

        $max = (int) $this->db->table('surveyor_api_log')->max('id');
        if ($max % 50 === 0) {
            $this->db->table('surveyor_api_log')->where('id', '<=', $max - self::ROWS)->delete();
        }
    }

    /** Pause a service: 429 an hour (or Retry-After), 401/403 six hours, errors 15 minutes. */
    public function pauseFor(string $service, int $status, ?int $retryAfter = null): int
    {
        $seconds = match (true) {
            $status === 429 => max(60, min(86400, $retryAfter ?? 3600)),
            $status === 401, $status === 403 => 6 * 3600,
            $status >= 500, $status === 0 => 900,
            default => 0,
        };

        if ($seconds > 0) {
            $this->cache->put($this->key($service), time() + $seconds, $seconds);
        }

        return $seconds;
    }

    public function pausedUntil(string $service): ?int
    {
        $until = (int) $this->cache->get($this->key($service), 0);

        return $until > time() ? $until : null;
    }

    public function resume(string $service): void
    {
        $this->cache->forget($this->key($service));
    }

    /** Calls made today (UTC) for an action, for the daily caps. */
    public function today(string $service, ?string $action = null): int
    {
        return $this->db->table('surveyor_api_log')
            ->where('service', $service)
            ->when($action !== null, fn ($q) => $q->where('action', $action))
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->count();
    }

    private function key(string $service): string
    {
        return 'ernestdefoe-surveyor.pause.'.$service;
    }
}
