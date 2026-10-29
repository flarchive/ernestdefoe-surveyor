<?php

use Ernestdefoe\Surveyor\Api;
use Ernestdefoe\Surveyor\Console;
use Ernestdefoe\Surveyor\IndexNow;
use Ernestdefoe\Surveyor\Rules\Rule;
use Ernestdefoe\Surveyor\Rules\RuleSet;
use Ernestdefoe\Surveyor\Settings;
use Ernestdefoe\Surveyor\Links\RelatedBlock;
use Flarum\Discussion\Event as DiscussionEvent;
use Flarum\Extend;
use Flarum\Frontend\AssetManager;
use Flarum\Post\Event as PostEvent;
use Flarum\Settings\Event\Saved as SettingsSaved;
use Illuminate\Console\Scheduling\Event;

$settings = new Extend\Settings();

foreach (Settings::DEFAULTS as $key => $value) {
    $settings->default(Settings::key($key), $value);
}

return [
    // Admin. The forum loads nothing of Surveyor's (no JS, no CSS, no payload)
    // unless the related-discussions block is switched on, below. The
    // workspace's sections are lazy chunks, fetched when each is opened.
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->jsDirectory(__DIR__.'/js/dist/admin')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    $settings,

    (new Extend\ServiceProvider())
        ->register(Ernestdefoe\Surveyor\Provider::class),

    // One upsert when a discussion changes, and only while IndexNow is on.
    (new Extend\Event())
        ->listen(DiscussionEvent\Started::class, [IndexNow\Listener::class, 'discussion'])
        ->listen(DiscussionEvent\Renamed::class, [IndexNow\Listener::class, 'discussion'])
        ->listen(DiscussionEvent\Hidden::class, [IndexNow\Listener::class, 'discussion'])
        ->listen(DiscussionEvent\Restored::class, [IndexNow\Listener::class, 'discussion'])
        ->listen(DiscussionEvent\Deleted::class, [IndexNow\Listener::class, 'discussion'])
        ->listen(PostEvent\Posted::class, [IndexNow\Listener::class, 'posted'])
        ->listen(PostEvent\Revised::class, [IndexNow\Listener::class, 'post'])
        ->listen(PostEvent\Hidden::class, [IndexNow\Listener::class, 'post'])
        ->listen(PostEvent\Restored::class, [IndexNow\Listener::class, 'post'])
        ->listen(PostEvent\Deleted::class, [IndexNow\Listener::class, 'post'])
        ->listen('eloquent.saved: '.Rule::class, fn () => resolve(RuleSet::class)->flush())
        ->listen('eloquent.deleted: '.Rule::class, fn () => resolve(RuleSet::class)->flush()),

    (new Extend\Console())
        ->command(Console\AuditCommand::class)
        ->command(Console\IndexNowCommand::class)
        ->schedule(Console\AuditCommand::class, function (Event $event): void {
            $event->everyMinute()->withoutOverlapping();
        })
        ->schedule(Console\IndexNowCommand::class, function (Event $event): void {
            $event->everyFiveMinutes()->withoutOverlapping();
        })
        ->command(Console\GoogleCommand::class)
        ->schedule(Console\GoogleCommand::class, function (Event $event): void {
            $event->hourly()->withoutOverlapping();
        })
        ->command(Console\SpeedCommand::class)
        ->schedule(Console\SpeedCommand::class, function (Event $event): void {
            $event->everyMinute()->withoutOverlapping();
        }),

    // Switching the related-discussions block on or off changes which scripts
    // the forum loads: have Flarum rebuild them on the next request.
    (new Extend\Event())
        ->listen(SettingsSaved::class, function (SettingsSaved $event): void {
            if (array_key_exists(Settings::key('related_enabled'), $event->settings)) {
                resolve(AssetManager::class)->markDirty();
            }
        }),

    // Off by default. Only while it is on does the forum load anything of
    // Surveyor's: a small script, and a cached list on the discussion API.
    (new Extend\Conditional())
        ->whenSetting(Settings::key('related_enabled'), '1', RelatedBlock::class),

    // The IndexNow key file: /{key}.txt, below the forum's folder.
    (new Extend\Routes('forum'))
        ->get('/{key:[0-9a-f]{32}}.txt', 'surveyor.indexnow.keyfile', IndexNow\KeyController::class),

    (new Extend\Routes('api'))
        ->get('/surveyor/summary', 'surveyor.summary.show', Api\SummaryController::class)
        ->get('/surveyor/audit', 'surveyor.audit.status', Api\AuditController::class)
        ->post('/surveyor/audit/start', 'surveyor.audit.start', Api\AuditController::class)
        ->post('/surveyor/audit/stop', 'surveyor.audit.stop', Api\AuditController::class)
        ->post('/surveyor/audit/step', 'surveyor.audit.step', Api\AuditController::class)
        ->get('/surveyor/pages', 'surveyor.pages.list', Api\PagesController::class)
        ->get('/surveyor/pages/{id:\d+}', 'surveyor.pages.show', Api\PagesController::class)
        ->get('/surveyor/duplicates', 'surveyor.pages.duplicates', Api\PagesController::class)
        ->get('/surveyor/links', 'surveyor.links.list', Api\LinksController::class)
        ->get('/surveyor/links/{id:\d+}/sources', 'surveyor.links.sources', Api\LinksController::class)
        ->post('/surveyor/links/{id:\d+}/recheck', 'surveyor.links.recheck', Api\LinksController::class)
        ->patch('/surveyor/links/{id:\d+}', 'surveyor.links.update', Api\LinksController::class)
        ->post('/surveyor/redirects', 'surveyor.links.redirect', Api\LinksController::class)
        ->get('/surveyor/missing', 'surveyor.links.missing', Api\LinksController::class)
        ->get('/surveyor/indexnow', 'surveyor.indexnow.show', Api\IndexNowController::class)
        ->post('/surveyor/indexnow/enable', 'surveyor.indexnow.enable', Api\IndexNowController::class)
        ->post('/surveyor/indexnow/disable', 'surveyor.indexnow.disable', Api\IndexNowController::class)
        ->post('/surveyor/indexnow/key', 'surveyor.indexnow.key', Api\IndexNowController::class)
        ->post('/surveyor/indexnow/submit', 'surveyor.indexnow.submit', Api\IndexNowController::class)
        ->get('/surveyor/rules', 'surveyor.rules.list', Api\RulesController::class)
        ->post('/surveyor/rules', 'surveyor.rules.create', Api\RulesController::class)
        ->patch('/surveyor/rules/{id:\d+}', 'surveyor.rules.update', Api\RulesController::class)
        ->delete('/surveyor/rules/{id:\d+}', 'surveyor.rules.delete', Api\RulesController::class)
        ->post('/surveyor/rules/test', 'surveyor.rules.test', Api\RulesController::class)
        ->get('/surveyor/diagnostics', 'surveyor.diagnostics.run', Api\DiagnosticsController::class)
        ->get('/surveyor/search', 'surveyor.search.show', Api\SearchController::class)
        ->post('/surveyor/search/connect', 'surveyor.search.connect', Api\SearchController::class)
        ->post('/surveyor/search/disconnect', 'surveyor.search.disconnect', Api\SearchController::class)
        ->post('/surveyor/search/refresh', 'surveyor.search.refresh', Api\SearchController::class)
        ->get('/surveyor/search/pages', 'surveyor.search.pages', Api\SearchController::class)
        ->get('/surveyor/search/queries', 'surveyor.search.queries', Api\SearchController::class)
        ->post('/surveyor/search/inspect', 'surveyor.search.inspect', Api\SearchController::class)
        ->get('/surveyor/search/sitemaps', 'surveyor.search.sitemaps', Api\SearchController::class)
        ->post('/surveyor/search/sitemaps', 'surveyor.search.submit', Api\SearchController::class)
        ->get('/surveyor/opportunities', 'surveyor.opportunities.list', Api\OpportunitiesController::class)
        ->get('/surveyor/speed', 'surveyor.speed.show', Api\SpeedController::class)
        ->post('/surveyor/speed', 'surveyor.speed.queue', Api\SpeedController::class)
        ->post('/surveyor/speed/key', 'surveyor.speed.key', Api\SpeedController::class)
        ->get('/surveyor/speed/history', 'surveyor.speed.history', Api\SpeedController::class)
        ->get('/surveyor/regressions', 'surveyor.insights.regressions', Api\InsightsController::class)
        ->get('/surveyor/internal-links', 'surveyor.insights.links', Api\InsightsController::class)
        ->get('/surveyor/related', 'surveyor.insights.related', Api\InsightsController::class)
        ->get('/surveyor/ai', 'surveyor.ai.show', Api\AiController::class)
        ->post('/surveyor/ai/key', 'surveyor.ai.key', Api\AiController::class)
        ->get('/surveyor/ai/candidates', 'surveyor.ai.candidates', Api\AiController::class)
        ->post('/surveyor/ai/preview', 'surveyor.ai.preview', Api\AiController::class)
        ->post('/surveyor/ai/apply', 'surveyor.ai.apply', Api\AiController::class)
        ->post('/surveyor/ai/undo', 'surveyor.ai.undo', Api\AiController::class),
];
