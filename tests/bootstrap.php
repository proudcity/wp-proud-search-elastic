<?php

/**
 * PHPUnit bootstrap for wp-proud-search-elastic.
 *
 * lib/elasticsearch.class.php cannot be loaded here: its first statement
 * requires ../../elasticpress/elasticpress.php, which pulls in the whole
 * ElasticPress plugin and, through it, WordPress. The units under test are
 * therefore kept in lib/teaser-filter-terms.php, which has no load-time
 * dependency on anything -- ElasticPress, WordPress or otherwise -- and is
 * included by both the plugin and this bootstrap.
 *
 * Run from the plugin root:
 *   composer install
 *   vendor/bin/phpunit
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/../lib/teaser-filter-terms.php';
