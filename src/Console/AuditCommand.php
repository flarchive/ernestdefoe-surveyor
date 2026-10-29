<?php

namespace Ernestdefoe\Surveyor\Console;

use Ernestdefoe\Surveyor\Crawl\Auditor;
use Ernestdefoe\Surveyor\Diagnostics\Checks;
use Ernestdefoe\Surveyor\Settings;
use Flarum\Console\AbstractCommand;
use Illuminate\Contracts\Cache\Repository as Cache;
use Symfony\Component\Console\Input\InputOption;

/**
 * Runs every minute from Flarum's scheduler: starts an audit when one is due,
 * then fetches up to "pages per minute" pages, evenly spaced, for under a
 * minute. A run carries over from tick to tick until it is done.
 */
class AuditCommand extends AbstractCommand
{
    public function __construct(
        protected Auditor $auditor,
        protected Settings $settings,
        protected Cache $cache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('surveyor:audit')
            ->setDescription('Continue (or start, when one is due) the Surveyor site audit.')
            ->addOption('start', null, InputOption::VALUE_NONE, 'Start an audit now if none is running.');
    }

    protected function fire(): int
    {
        $this->cache->forever(Checks::TICK_KEY, time());

        if ($this->input->getOption('start')) {
            $this->auditor->start('manual');
        } elseif ($this->auditor->due()) {
            $this->auditor->start('schedule');
        }

        $ppm = $this->settings->pagesPerMinute();
        $done = $this->auditor->work($ppm, 60 / $ppm, microtime(true) + 55);

        if ($done === null) {
            $this->info('Another process is working on the audit.');
        } elseif ($done > 0) {
            $this->info("Fetched $done.");
        }

        return 0;
    }
}
