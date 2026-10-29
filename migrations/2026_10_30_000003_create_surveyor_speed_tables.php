<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // PageSpeed Insights results: lab (Lighthouse) and field (Chrome UX Report) data, kept as history.
        $schema->create('surveyor_speed', function (Blueprint $table) {
            $table->increments('id');
            $table->char('url_hash', 40);
            $table->string('path', 1000);
            // mobile | desktop
            $table->string('strategy', 8);
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedInteger('lcp_ms')->nullable();
            $table->unsignedInteger('fcp_ms')->nullable();
            $table->unsignedInteger('tbt_ms')->nullable();
            $table->unsignedInteger('si_ms')->nullable();
            // Cumulative layout shift × 1000.
            $table->unsignedInteger('cls')->nullable();
            $table->unsignedInteger('field_lcp_ms')->nullable();
            $table->unsignedInteger('field_inp_ms')->nullable();
            $table->unsignedInteger('field_cls')->nullable();
            // FAST | AVERAGE | SLOW, from the Chrome UX Report
            $table->string('field_category', 10)->nullable();
            // url | origin: whether field data is this page's own or the whole site's
            $table->string('field_scope', 6)->nullable();
            $table->string('error', 255)->nullable();
            $table->dateTime('measured_at');

            $table->index(['url_hash', 'strategy', 'measured_at']);
            $table->index('measured_at');
        });

        $schema->create('surveyor_speed_queue', function (Blueprint $table) {
            $table->char('url_hash', 40);
            $table->string('strategy', 8);
            $table->string('path', 1000);
            $table->dateTime('queued_at');

            $table->primary(['url_hash', 'strategy']);
            $table->index('queued_at');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('surveyor_speed');
        $schema->dropIfExists('surveyor_speed_queue');
    },
];
