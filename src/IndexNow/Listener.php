<?php

namespace Ernestdefoe\Surveyor\IndexNow;

use Carbon\Carbon;
use Ernestdefoe\Surveyor\Settings;
use Flarum\Post\Event as PostEvent;
use Illuminate\Database\ConnectionInterface;

/**
 * Notes that a discussion changed. One upsert, nothing else: whether a guest
 * can see it, the rules and the hourly limit are all decided later, when the
 * scheduler sends the queue. With IndexNow off this does nothing at all
 * (settings are already in memory, so even the check costs no query).
 */
class Listener
{
    public function __construct(
        protected Settings $settings,
        protected ConnectionInterface $db,
    ) {
    }

    /** Lazily made: registered as [Listener::class, method], so a request with no such event never builds it. */
    public function discussion(object $event): void
    {
        $this->touch((int) $event->discussion->id);
    }

    public function post(object $event): void
    {
        $this->touch((int) $event->post->discussion_id);
    }

    /** The first post arrives with Started; only replies are new here. */
    public function posted(PostEvent\Posted $event): void
    {
        if ((int) $event->post->number > 1) {
            $this->touch((int) $event->post->discussion_id);
        }
    }

    public function touch(int $discussionId): void
    {
        if ($discussionId <= 0 || ! $this->settings->indexNowActive()) {
            return;
        }

        $this->db->table('surveyor_indexnow_queue')->upsert(
            [['discussion_id' => $discussionId, 'pending' => true, 'queued_at' => Carbon::now()]],
            ['discussion_id'],
            ['pending', 'queued_at']
        );
    }
}
