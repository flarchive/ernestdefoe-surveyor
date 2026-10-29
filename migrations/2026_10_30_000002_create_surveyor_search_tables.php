<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Each page's search performance: the last 28 days and the 28 before.
        $schema->create('surveyor_search_pages', function (Blueprint $table) {
            $table->increments('id');
            $table->char('url_hash', 40)->unique();
            $table->string('path', 1000);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            // Average position × 100 (so 8.25 is 825).
            $table->unsignedInteger('position')->nullable();
            $table->unsignedInteger('prev_clicks')->default(0);
            $table->unsignedInteger('prev_impressions')->default(0);
            $table->unsignedInteger('prev_position')->nullable();
            $table->date('period_end');
            $table->dateTime('updated_at');

            $table->index('impressions');
            $table->index('clicks');
        });

        // The queries each page is found for (last 28 days), replaced on every refresh.
        $schema->create('surveyor_search_queries', function (Blueprint $table) {
            $table->char('url_hash', 40);
            $table->char('query_hash', 40);
            $table->string('query', 500);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('position')->nullable();

            $table->primary(['url_hash', 'query_hash']);
            $table->index(['url_hash', 'impressions']);
        });

        // The whole site, day by day (Search Console keeps 16 months; so do we).
        $schema->create('surveyor_search_days', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('position')->nullable();
        });

        // Each page, week by week, for its history and the regression guard.
        $schema->create('surveyor_search_history', function (Blueprint $table) {
            $table->char('url_hash', 40);
            $table->date('week');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('position')->nullable();

            $table->primary(['url_hash', 'week']);
            $table->index('week');
        });

        // What Google's URL Inspection said about a page, last time it was asked.
        $schema->create('surveyor_inspections', function (Blueprint $table) {
            $table->char('url_hash', 40)->primary();
            $table->string('path', 1000);
            $table->string('verdict', 20)->nullable();
            $table->string('coverage', 120)->nullable();
            $table->string('indexing_state', 40)->nullable();
            $table->string('fetch_state', 40)->nullable();
            $table->string('robots_state', 20)->nullable();
            $table->string('google_canonical', 1000)->nullable();
            $table->string('rich_verdict', 20)->nullable();
            $table->dateTime('last_crawl_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->dateTime('inspected_at');

            $table->index('inspected_at');
        });
    },
    'down' => function (Builder $schema) {
        foreach (['surveyor_search_pages', 'surveyor_search_queries', 'surveyor_search_days', 'surveyor_search_history', 'surveyor_inspections'] as $table) {
            $schema->dropIfExists($table);
        }
    },
];
