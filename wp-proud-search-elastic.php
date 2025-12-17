<?php

/**
Plugin Name:        Proud Search Elastic
Plugin URI:         http://getproudcity.com
Description:        ProudCity distribution
Version:            2025.12.17.0700
Author:             ProudCity
Author URI:         http://getproudcity.com

License:            Affero GPL v3

@package   Wp_Proud_Search_Elastic
@author    ProudCity <info@proudcity.com>
@copyright 2025 ProudCity
 **/

// Elastic Search?
if (class_exists('ElasticPress\Elasticsearch')) {
	include_once plugin_dir_path(__FILE__) . 'lib/elasticsearch.class.php';
}

// @NOTE we are dropping the CLI integration for now in favor of using `wp elasticpress`
// /**
//  * WP CLI Commands
//  */
// if ( defined( 'WP_CLI' ) && WP_CLI ) {
//     require_once(  plugin_dir_path(__FILE__) . 'bin/wp-cli.php' );
// }

// Settings page
if (is_admin()) {
	if (class_exists('ProudSettingsPage')) {
		function proud_search_elastic_settings()
		{
			include_once plugin_dir_path(__FILE__) . 'settings/elastic-settings.php';
		}
		add_action('init', 'proud_search_elastic_settings');
	} else {
		include_once plugin_dir_path(__FILE__) . 'settings/proud-elastic-agent.php';
	}
}
