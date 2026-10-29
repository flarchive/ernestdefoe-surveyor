<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // One row per audit. The run that is still going holds its progress in
        // `state`, so a crawl that was stopped picks up where it left off.
        $schema->create('surveyor_runs', function (Blueprint $table) {
            $table->increments('id');
            // running | done | stopped
            $table->string('status', 10)->default('running');
            // robots | sitemap | crawl
            $table->string('phase', 10)->default('robots');
            // schedule | manual
            $table->string('origin', 10)->default('schedule');
            $table->unsignedInteger('pages_queued')->default(0);
            $table->unsignedInteger('pages_done')->default(0);
            $table->unsignedInteger('pages_failed')->default(0);
            $table->unsignedSmallInteger('avg_score')->nullable();
            $table->mediumText('state')->nullable();
            // Held by whichever process is crawling (the scheduler or the admin
            // page), so two never fetch for the same run at once.
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();

            $table->index(['status', 'id']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('surveyor_runs');
    },
];
