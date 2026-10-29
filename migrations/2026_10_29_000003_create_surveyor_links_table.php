<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Internal links: page `source_id` links to page `target_id`. Replaced
        // whenever the source page is audited again.
        $schema->create('surveyor_links', function (Blueprint $table) {
            $table->unsignedInteger('source_id');
            $table->unsignedInteger('target_id');

            $table->primary(['source_id', 'target_id']);
            $table->index('target_id');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('surveyor_links');
    },
];
