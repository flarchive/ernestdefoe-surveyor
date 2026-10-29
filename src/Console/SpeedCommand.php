<?php

namespace Ernestdefoe\Surveyor\Console;

use Ernestdefoe\Surveyor\Settings;
use Ernestdefoe\Surveyor\Speed\PageSpeed;
use Flarum\Console\AbstractCommand;

/**
 * Every minute from the scheduler: queues the weekly measurements when they
 * are switched on and due, then makes at most one PageSpeed measurement.
 */
class SpeedCommand extends AbstractCommand
{
    public function __construct(
        protected PageSpeed $speed,
        protected Settings $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('surveyor:speed')->setDescription('Make the next queued PageSpeed Insights measurement.');
    }

    protected function fire(): int
    {
        if ($this->settings->get('speed_schedule') === 'weekly' && (int) $this->settings->get('speed_scheduled_at') < time() - 7 * 86400) {
            foreach ($this->speed->scheduledPages($this->settings->int('speed_pages', 1, 100)) as $path) {
                $this->speed->queue($path);
            }
            $this->settings->set('speed_scheduled_at', (string) time());
        }

        if ($this->speed->next()) {
            $this->info('Measured one page.');
        }

        return 0;
    }
}
