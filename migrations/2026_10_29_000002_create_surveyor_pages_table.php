<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // One row per address on the forum: the crawl queue, the latest audit
        // of the page, and the destination of the internal links pointing at it.
        $schema->create('surveyor_pages', function (Blueprint $table) {
            $table->increments('id');
            $table->char('url_hash', 40)->unique();
            // The path below the forum's own address, with any query string.
            $table->string('path', 1000);
            // sitemap | link | home
            $table->string('found_via', 10)->default('link');
            // The run that still has to fetch it; null once fetched.
            $table->unsignedInteger('queued_run')->nullable();
            $table->unsignedInteger('last_seen_run')->nullable();
            $table->dateTime('crawled_at')->nullable();

            // ok | redirect | broken | restricted | challenge | failed
            $table->string('classification', 12)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('final_url', 1000)->nullable();
            $table->string('error', 255)->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->unsignedInteger('bytes')->nullable();

            $table->string('title', 500)->nullable();
            $table->unsignedSmallInteger('title_length')->nullable();
            $table->char('title_hash', 40)->nullable();
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('description_length')->nullable();
            $table->char('description_hash', 40)->nullable();
            $table->string('canonical', 1000)->nullable();
            $table->boolean('canonical_self')->nullable();
            $table->boolean('noindex')->nullable();
            $table->unsignedSmallInteger('h1_count')->nullable();
            $table->unsignedInteger('word_count')->nullable();
            $table->unsignedSmallInteger('links_out')->nullable();
            $table->unsignedSmallInteger('images_no_alt')->nullable();
            // none | valid | invalid
            $table->string('structured_data', 8)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            // Short issue codes, as JSON; the admin translates them.
            $table->text('issues')->nullable();

            // An admin's "ignore" in link recovery.
            $table->boolean('link_ignored')->default(false);

            $table->index(['queued_run', 'id']);
            $table->index(['crawled_at']);
            $table->index(['classification', 'link_ignored']);
            $table->index(['score']);
            $table->index(['title_hash']);
            $table->index(['description_hash']);
            $table->index(['last_seen_run']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('surveyor_pages');
    },
];
