<?php

namespace Ernestdefoe\Surveyor\Links;

use Ernestdefoe\Surveyor\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Extend\ExtenderInterface;

/**
 * What the related-discussions block adds to the forum, applied only while
 * the block is switched on (extend.php, Extend\Conditional): a small script
 * and stylesheet, and a cached list on the single-discussion API response.
 */
class RelatedBlock
{
    /** @return list<ExtenderInterface> */
    public function __invoke(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            (new Extend\Frontend('forum'))
                ->js($root.'/js/dist/forum.js')
                ->css($root.'/less/forum.less'),

            (new Extend\ApiResource(DiscussionResource::class))
                ->fields(fn () => [
                    Schema\Arr::make('surveyorRelated')
                        ->visible(fn (Discussion $discussion, Context $context) => $context->showing(DiscussionResource::class))
                        ->get(fn (Discussion $discussion) => resolve(Related::class)->cached($discussion, resolve(Settings::class)->int('related_count', 1, 10))),
                ]),
        ];
    }
}
