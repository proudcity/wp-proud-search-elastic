<?php
/*
Plugin Name:        Proud Search Elastic
Plugin URI:         http://getproudcity.com
Description:        ProudCity distribution
Version:            1.0.0
Author:             ProudCity
Author URI:         http://getproudcity.com

License:            Affero GPL v3
*/


// Elastic Search?
if ( class_exists( 'ElasticPress\Elasticsearch' ) ) {
  require_once( plugin_dir_path(__FILE__) . 'lib/elasticsearch.class.php' );
}

/**
 * WP CLI Commands
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once(  plugin_dir_path(__FILE__) . 'bin/wp-cli.php' );
}

// Settings page
if( is_admin() ) {
  if( class_exists( 'ProudSettingsPage' ) ) {
    function proud_search_elastic_settings() {
      require_once( plugin_dir_path(__FILE__) . 'settings/elastic-settings.php' );
    }
    add_action( 'init', 'proud_search_elastic_settings' );
  }
  else {
    require_once( plugin_dir_path(__FILE__) . 'settings/proud-elastic-agent.php' );
  }
}