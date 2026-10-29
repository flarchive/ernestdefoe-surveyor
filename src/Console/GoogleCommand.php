<?php

namespace Ernestdefoe\Surveyor\Console;

use Ernestdefoe\Surveyor\Google\SearchSync;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/** Hourly from the scheduler; refreshes Search Console data when it is due (daily by default). */
class GoogleCommand extends AbstractCommand
{
    public function __construct(
        protected SearchSync $sync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('surveyor:google')
            ->setDescription('Refresh Search Console data when it is due.')
            ->addOption('now', null, InputOption::VALUE_NONE, 'Refresh now, even if it is not due.');
    }

    protected function fire(): int
    {
        if (! $this->input->getOption('now') && ! $this->sync->due()) {
            return 0;
        }

        $error = $this->sync->refresh();
        $error === null ? $this->info('Search Console data refreshed.') : $this->error('Search Console: '.$error);

        return 0;
    }
}
