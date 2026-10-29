<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // API keys and the Google service account, encrypted. Kept out of the
        // settings table on purpose: every setting is sent to the admin page.
        $schema->create('surveyor_secrets', function (Blueprint $table) {
            $table->string('name', 40)->primary();
            $table->text('value');
            // A short, safe description of what is stored (a service account's email).
            $table->string('label', 255)->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // Every call to Google, PageSpeed or the AI provider: for Diagnostics, quotas and spend.
        $schema->create('surveyor_api_log', function (Blueprint $table) {
            $table->increments('id');
            // google | pagespeed | ai
            $table->string('service', 12);
            $table->string('action', 30);
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('message', 255)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->dateTime('created_at');

            $table->index(['service', 'created_at']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('surveyor_secrets');
        $schema->dropIfExists('surveyor_api_log');
    },
];
