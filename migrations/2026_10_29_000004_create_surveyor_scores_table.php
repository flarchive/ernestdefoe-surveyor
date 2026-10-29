<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // A page's score in each audit, for its history. Old runs are pruned.
        $schema->create('surveyor_scores', function (Blueprint $table) {
            $table->unsignedInteger('run_id');
            $table->unsignedInteger('page_id');
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();

            $table->primary(['run_id', 'page_id']);
            $table->index(['page_id', 'run_id']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('surveyor_scores');
    },
];
