<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('surveyor_rules', function (Blueprint $table) {
            $table->increments('id');
            // exact | wildcard | regex
            $table->string('type', 10);
            $table->string('pattern', 300);
            // exclude | include
            $table->string('action', 10)->default('exclude');
            // audit | indexnow | both
            $table->string('scope', 10)->default('both');
            $table->boolean('enabled')->default(true);
            $table->string('note', 255)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('surveyor_rules');
    },
];
