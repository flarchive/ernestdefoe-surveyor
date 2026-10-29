<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // One suggested description per discussion: previewed, then applied (or not).
        $schema->create('surveyor_ai_descriptions', function (Blueprint $table) {
            $table->unsignedInteger('discussion_id')->primary();
            $table->string('suggestion', 300);
            // suggested | applied | undone
            $table->string('status', 10)->default('suggested');
            // What FoF SEO had before it was applied, so it can be put back.
            $table->text('previous')->nullable();
            $table->boolean('previous_auto')->nullable();
            $table->boolean('previous_row')->default(false);
            $table->dateTime('created_at');
            $table->dateTime('applied_at')->nullable();

            $table->index('status');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('surveyor_ai_descriptions');
    },
];
