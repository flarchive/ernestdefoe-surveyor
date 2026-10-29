<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // One row per discussion, however often it changes: a busy discussion
        // is sent once, then at most once an hour.
        $schema->create('surveyor_indexnow_queue', function (Blueprint $table) {
            $table->unsignedInteger('discussion_id')->primary();
            $table->boolean('pending')->default(true);
            $table->dateTime('queued_at');
            // The address last sent, so a renamed or removed discussion's old
            // address can be sent again (search engines then see it is gone).
            $table->string('url', 1000)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();

            $table->index(['pending', 'submitted_at']);
        });

        $schema->create('surveyor_indexnow_log', function (Blueprint $table) {
            $table->increments('id');
            // schedule | manual
            $table->string('origin', 10);
            $table->unsignedInteger('url_count');
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('message', 255)->nullable();
            $table->text('sample')->nullable();
            $table->dateTime('created_at');

            $table->index('created_at');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('surveyor_indexnow_queue');
        $schema->dropIfExists('surveyor_indexnow_log');
    },
];
