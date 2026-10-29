<?php

use Flarum\Testing\integration\Setup\SetupScript;

require __DIR__.'/../../vendor/autoload.php';

/*
 * Reads the database from the environment (DB_DRIVER, DB_DATABASE, DB_HOST,
 * DB_PORT, DB_USERNAME, DB_PASSWORD, DB_PREFIX) and the folder for the test
 * install from FLARUM_TEST_TMP_DIR_LOCAL. Locally, SQLite:
 *
 *   DB_DRIVER=sqlite DB_DATABASE=/path/to/surveyor-test.sqlite \
 *   FLARUM_TEST_TMP_DIR_LOCAL=tests/integration/tmp composer test:setup
 */
$setup = new SetupScript();

$setup->run();
