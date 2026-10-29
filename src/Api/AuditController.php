<?php

namespace Ernestdefoe\Surveyor\Api;

use Ernestdefoe\Surveyor\Crawl\Auditor;
use Ernestdefoe\Surveyor\Settings;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Start, stop, and "step": the admin page keeps an audit moving while it is
 * open, for forums whose scheduler is not running. A step makes a few
 * fetches at the same pace the scheduler would, and the page asks for the
 * next one ten seconds later.
 */
class AuditController extends Controller
{
    protected array $actions = ['start', 'stop', 'step', 'status'];

    public function __construct(
        protected Auditor $auditor,
        protected Settings $settings,
    ) {
    }

    /** @return array<string, mixed> */
    protected function start(ServerRequestInterface $request): array
    {
        $this->auditor->start('manual');

        return $this->status($request);
    }

    /** @return array<string, mixed> */
    protected function stop(ServerRequestInterface $request): array
    {
        $this->auditor->stop();

        return $this->status($request);
    }

    /** @return array<string, mixed> */
    protected function step(ServerRequestInterface $request): array
    {
        $ppm = $this->settings->pagesPerMinute();
        // Within PHP's usual 30 second limit for a web request.
        $done = $this->auditor->work(max(1, min(10, (int) ceil($ppm / 6))), 60 / $ppm, microtime(true) + 25);

        return ['busy' => $done === null] + $this->status($request);
    }

    /** @return array<string, mixed> */
    protected function status(ServerRequestInterface $request): array
    {
        $run = $this->auditor->current() ?? $this->auditor->latest();

        return ['run' => $run ? self::run($run) : null];
    }

    /** @return array<string, mixed> */
    public static function run(object $run): array
    {
        return [
            'id' => (int) $run->id,
            'status' => $run->status,
            'phase' => $run->phase,
            'origin' => $run->origin,
            'pagesQueued' => (int) $run->pages_queued,
            'pagesDone' => (int) $run->pages_done,
            'pagesFailed' => (int) $run->pages_failed,
            'avgScore' => $run->avg_score === null ? null : (int) $run->avg_score,
            'startedAt' => self::time($run->started_at),
            'finishedAt' => self::time($run->finished_at),
        ];
    }
}
