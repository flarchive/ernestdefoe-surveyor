<?php

namespace Ernestdefoe\Surveyor\Console;

use Ernestdefoe\Surveyor\IndexNow\Sender;
use Flarum\Console\AbstractCommand;

class IndexNowCommand extends AbstractCommand
{
    public function __construct(
        protected Sender $sender,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('surveyor:indexnow')->setDescription('Send discussions that changed to IndexNow.');
    }

    protected function fire(): int
    {
        $sent = $this->sender->sendQueue();

        if ($sent > 0) {
            $this->info("Sent $sent address(es) to IndexNow.");
        }

        return 0;
    }
}
