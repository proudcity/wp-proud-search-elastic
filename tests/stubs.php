<?php

/**
 * Minimal WordPress stubs for wp-proud-search-elastic tests.
 *
 * Only covers what teaser-filter-terms.php touches at include time. Everything
 * a test needs to vary is mocked per-test with Brain Monkey instead, so these
 * are all function_exists-guarded and deliberately dumb.
 */

namespace {
    if (!function_exists('add_action')) {
        function add_action() { return true; }
    }
    if (!function_exists('add_filter')) {
        function add_filter() { return true; }
    }

    // WP_Error is only ever type-checked here (wp_count_terms() returns one for
    // an unregistered taxonomy), so a marker class is enough.
    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            public $errors = [];

            public function __construct($code = '', $message = '')
            {
                if ($code) {
                    $this->errors[$code] = [$message];
                }
            }
        }
    }
}

// Stand-in for wp-proud-core's resolver, so the delegation path in
// TeaserFilterTerms::resolve_slugs() can be exercised without loading
// wp-proud-core. Tests set CoreResolverStub::$return to steer it.
namespace Proud\Core {
    class CoreResolverStub
    {
        public static $return = [];
        public static $calls = [];
    }

    if (!function_exists('Proud\Core\resolve_taxonomy_filter_slugs')) {
        function resolve_taxonomy_filter_slugs($values, $taxonomy)
        {
            CoreResolverStub::$calls[] = [$values, $taxonomy];
            return CoreResolverStub::$return;
        }
    }
}
