<?php
/**
 * @author ProudCity
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

require_once( plugin_dir_path(__FILE__) . '../../elasticpress/elasticpress.php' );

define( 'ATTACHMENT_MAX', 25 );
define( 'EVENT_DATE_FIELD', '_event_start_local' );
define( 'MEETING_DATE_FIELD', 'datetime' );

class ProudElasticSearch {

    /**
     * Array of sites that elastic search is using
     * @var array
     */
    public $search_cohort;

    /**
     * The index name of this site
     * @var string
     */
    public $index_name;

    /**
     * Mode we're operating in
     * @var string
     */
    public $agent_type;

    /**
     * Flag for CLI to force docs to be posted in _update
     * @var bool
     */
    public $enable_post_update = false;

    /**
     * Flag for CLI to force attachments to be posted initially
     * @var bool
     */
    public $force_attachments = false;

    /**
     * Tracking post types and meta fields that have document indexing
     * @var array
     */
    public $attachments = [
        'document' => [
            'document',
        ],
        'meeting'  => [
            'agenda_attachment',
            'minutes_attachment',
        ],
    ];

    /**
     * The forms we should alter with aggregations, ect
     * @var array
     */
    public $forms = [];

    /**
     * Result counts
     * @var array
     */
    public static $aggregations;

    /**
     * URL to post to the documents helper api
     *
     * @var string
     */
    public $attachments_api;

    /**
     * @var ElasticPress\Feature\Documents\Documents
     */
    public $EPDocuments;

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'plugins_loaded', array( $this, 'check_modules' ) );

        // Modifying indexes to allow our idea of multisite
        // -----------------------------------

        // Set Search cohort
        $this->search_cohort = get_option( 'proud-elastic-search-cohort' );
        // Set index name
        $this->index_name = get_option( 'proud-elastic-index-name' );
        // Set agent type
        $this->agent_type = get_option( 'proud-elastic-agent-type', 'agent' );
        // Alter index names to match our cohort
        add_filter( 'ep_index_name', array( $this, 'ep_index_name' ), 10, 2 );
        // Alter shard count
        add_filter( 'ep_default_index_number_of_shards', array( $this, 'ep_default_index_number_of_shards' ) );
        // Are we processing attachments?
        $this->attachments_api = defined( 'EP_HELPER_HOST' ) ? EP_HELPER_HOST : false;

        // Deal with elastic mapping
        // -----------------------------------

        add_filter( 'ep_meta_mode', [ $this, 'ep_meta_mode' ] );

        // DOCUMENTS: Alter mapping sent to ES (USE STOCK EP DOCUMENTS FUNCs)
        if ( $this->attachments_api ) {
            $this->EPDocuments = new ElasticPress\Feature\Documents\Documents();
            add_action( 'ep_cli_put_mapping', [ $this->EPDocuments, 'create_pipeline' ] );
            add_action( 'ep_dashboard_put_mapping', [ $this->EPDocuments, 'create_pipeline' ] );
            add_filter( 'ep_config_mapping', [ $this->EPDocuments, 'attachments_mapping' ] );
            // add_filter( 'ep_post_mapping', [ $this->EPDocuments, 'attachments_mapping' ] );
        }

        // Allow meta mappings
        add_filter( 'ep_prepare_meta_allowed_protected_keys', array(
            $this,
            'ep_prepare_meta_allowed_protected_keys'
        ), 10 );

        // Search all in cohort
        if ( $this->agent_type === 'full' ) {
            if ( ! defined( 'EP_IS_NETWORK' )) {
                define( 'EP_IS_NETWORK', true );
            }
            add_filter( 'ep_global_alias', array( $this, 'ep_global_alias_full' ) );
        } // Search only this site
        else {
            add_filter( 'ep_global_alias', array( $this, 'ep_global_alias_single' ) );
        }

        // Events-manager stub
        // -----------------------------------
        add_action( 'wp_insert_post', array( $this, 'em_save_events' ), 999, 3 );

        // Posting to elastic
        // -----------------------------------

        add_filter( 'ep_post_sync_args_post_prepare_meta', array(
            $this,
            'ep_post_sync_args_post_prepare_meta'
        ), 999, 1 );

        // If we're only in agent mode, don't load proud
        if ( $this->agent_type === 'agent' ) {
            return;
        } // Add an alter to search page
        else if ( $this->agent_type === 'subsite' ) {
            add_filter( 'proud_search_page_message', array( $this, 'search_page_message' ) );
        }

        // Allow edits for $enable_post_update to _update
        add_filter( 'ep_index_post_request_path', array(
            $this,
            'ep_index_post_request_path'
        ), 999, 2 );

        // Allow edits for $enable_post_update to POST, formatting
        add_filter( 'ep_index_post_request_args', array(
            $this,
            'ep_index_post_request_args'
        ), 999, 2 );

        // Searching
        // ------------------------------------

        // Modify search page template
        add_filter( 'proud_search_page_template', array( $this, 'search_page_template' ) );

        // DOCUMENTS: Add attachment to search fields
        if ( $this->attachments_api ) {
            add_filter( 'ep_search_fields', array( $this, 'ep_search_fields' ) );
        }

        // Integrate with proud teaser plugin + elasticpress
        // -----------------------------------

        // Modify settings for widgets
        add_filter( 'proud_teaser_settings', array( $this, 'proud_teaser_settings' ), 10, 2 );
        add_filter( 'proud_teaser_extra_options', array( $this, 'proud_teaser_extra_options' ), 10, 2 );
        // Alter proud search queries
        add_filter( 'wpss_search_query_args', array( $this, 'query_alter' ), 10, 2 );
        add_filter( 'proud_teaser_query_args', array( $this, 'query_alter' ), 10, 2 );
        // Enable elastic search if
        add_filter( 'ep_elasticpress_enabled', array( $this, 'ep_enabled' ), 20, 2 );
        // Modify proud teaser filters
        add_action( 'proud-teaser-filters', array( $this, 'proud_teaser_filters' ), 10, 2 );
        // Modify proud teaser display if there is a search active
        add_filter( 'proud_teaser_post_type', array( $this, 'proud_teaser_post_type' ), 10, 2 );
        add_filter( 'proud_teaser_display_type', array( $this, 'proud_teaser_display_type' ), 10, 2 );
        // Default "Fuzziness"
        add_filter( 'ep_post_match_fuzziness' , array( $this, 'ep_post_match_fuzziness' ), 10, 3 );
        // Allow ep to weight "decaying"?
        add_filter( 'ep_is_decaying_enabled', [ $this, 'ep_is_decaying_enabled' ], 10, 3 );
        // Allow ep to apply weighting?
        add_filter( 'ep_enable_do_weighting', [ $this, 'ep_enable_do_weighting' ], 10, 4 );
        // Add our weighting, ect
        add_filter( 'ep_formatted_args', array( $this, 'ep_weight_search' ), 10, 2 );
        // Alter request path
        add_filter( 'ep_search_request_path', array( $this, 'ep_search_request_path' ), 10, 5 );
        // Get aggregations
        add_action( 'ep_retrieve_aggregations', array( $this, 'ep_retrieve_aggregations' ), 10, 1 );
        // Modify form output
        add_filter( 'proud-form-filled-fields', array( $this, 'form_filled_fields' ), 10, 3 );

        // UI + proud-search integration
        // -----------------------------------

        // Respond to search retrieval
        add_filter( 'ep_retrieve_the_post', array( $this, 'ep_retrieve_the_post' ), 10, 2 );
        // Make sure our fields are added
        add_filter( 'ep_search_post_return_args', array( $this, 'ep_search_post_return_args' ) );
        // Helpers to display post as we want
        add_filter( 'proud_teaser_has_thumbnail', array( $this, 'proud_teaser_has_thumbnail' ), 10, 1);
        add_filter( 'proud_teaser_thumbnail', array( $this, 'proud_teaser_thumbnail' ), 10, 2 );
        add_filter( 'the_title', array( $this, 'the_title' ), 10, 2 );
        add_filter( 'post_link', array( $this, 'post_link' ), 10, 2 );
        add_filter( 'post_type_link', array( $this, 'post_link' ), 10, 2 );
        add_filter( 'post_class', array( $this, 'post_class' ), 10, 3 );
        // Add matching to search results
        add_action( 'teaser_search_matching', array( $this, 'teaser_search_matching' ) );
        // Alter search results
        add_filter( 'proud_search_post_url', array( $this, 'search_post_url' ), 10, 2 );
        add_filter( 'proud_search_post_args', array( $this, 'search_post_args' ), 10, 2 );
        // Alter ajax searchr results
        add_filter( 'proud_search_ajax_post', array( $this, 'search_ajax_post' ), 10, 2 );
    }


    /**
     * Sets alert message on admin
     */
    public function modules_error() {
        $class   = 'notice notice-error';
        $message = __( 'Proud ElasticSearch functions best when all ElasticPress modules are disabled, please head over and make sure: ', 'proud-elasticsearch' );
        printf(
            '<div class="%1$s"><p>%2$s%3$s</p></div>',
            $class,
            $message,
            '<a href="/wp-admin/admin.php?page=elasticpress">disable modules</a>.'
        );
    }

    /**
     * Makes sure ElasticPress modules aren't enabled
     */
    public function check_modules() {
        $active_ep = get_option( 'ep_feature_settings', array() );
        if ( ! empty( $active_ep ) ) {
            foreach ( $active_ep as $feature ) {
                if ( $feature['active'] ) {
                    add_action( 'admin_notices', array( $this, 'modules_error' ) );
                    break;
                }
            }
        }
    }

    /**
     * Alters index name to our set value
     */
    public function ep_index_name( $index_name, $blog_id ) {
        return $this->index_name;
    }

    /**
     * Alters number of shards
     */
    public function ep_default_index_number_of_shards() {
        return 2;
    }

    /**
     * Returns the current meta mode. 
     * see Weighting->get_meta_mode
     */
    public function ep_meta_mode( $meta_mode ) {
        return 'auto';
    }

    /**
     * Alters es mapping
     * see Post->get_distinct_meta_field_keys_db
     */
    public function ep_prepare_meta_allowed_protected_keys( $allowed_protected_keys ) {
        // Adding event end timestamp
        $allowed_protected_keys[] = EVENT_DATE_FIELD;
        // Adding agency "exlude lists" meta
        $allowed_protected_keys[] = 'list_exclude';
        // Adding images markup
        $allowed_protected_keys[] = 'post_thumbnails';

        return $allowed_protected_keys;
    }

    /**
     * Alters the network alias to use specific values
     */
    public function ep_global_alias_single( $alias ) {
        return $this->index_name;
    }

    /**
     * Alters the network alias to use specific values
     */
    public function ep_global_alias_full( $alias ) {
        return implode( ',', array_keys( $this->search_cohort ) );
    }

    /**
     * Deal with events-manager not saving the events in a recurring set
     *
     * @param $post_ID
     * @param $post
     * @param $update
     */
    public function em_save_events( $post_id, $post, $update ) {

        if ( $post->post_type === 'event-recurring' && $post->post_status !== 'auto-draft' ) {
            // If this is a revision, don't bother
            if ( wp_is_post_revision( $post_id ) ) {
                return;
            }

            $recurring    = new EM_EVENT( $post );
            $events_array = EM_Events::get( [
                'recurrence_id' => $recurring->event_id,
                'scope'         => 'all',
                'status'        => 'everything'
            ] );

            if ( empty( $events_array ) ) {
                return;
            }

            $syncManager = new EP_Sync_Manager();
            foreach ( $events_array as $event ) {
                if ( $recurring->event_id != $event->recurrence_id ) {
                    continue;
                }
                $syncManager->sync_post( $event->post_id );
            }
        }
    }

    /**
     * Gets the path suffix for a post in elastic
     */
    public function indexed_post_path( $id ) {
        return trailingslashit( $this->index_name ) . 'post/' . $id;
    }

    /**
     * See elasticpress/features/documents/documents/
     * func ep_documents_index_post_request_path
     */
    public function ep_document_request_path( $id ) {
        return $this->indexed_post_path( $id ) . '?pipeline='
               . apply_filters( 'ep_documents_pipeline_id', $this->index_name . '-attachment' );
    }

    /**
     * Posts to helper api
     *
     * @param $post_args
     * @param $attachments_meta
     */
    public function post_to_helper_api( $post_args, $attachments_meta ) {
        // For some reason the live version is trying to send this request
        // multiple times in a row...
        // @TODO figure out why
        static $currently_calling = null;
        if ( $currently_calling === $post_args['ID'] ) {
            return;
        }
        // Set our static
        $currently_calling = $post_args['ID'];

        //Deal with posting
        $args = [
            'method'  => 'POST',
            'headers' => array(),
            'body'    => new stdClass,
        ];

        $args['body']->indexedPath      = $this->indexed_post_path( $post_args['ID'] );
        $args['body']->path             = $this->ep_document_request_path( $post_args['ID'] );
        $args['body']->attachments_meta = $attachments_meta;
        $args['body']->post             = $post_args;

        // Converting body to array
        $args['body'] = (array) $args['body'];

        try {
            wp_remote_request( $this->attachments_api, $args );
        } catch (\Exception $e) {
            error_log( '[elasticsearch] Failed sending to elastic docs API: ' . $e->getMessage() );
        }
    }

    /**
     * Check if the meta values are still being sent
     *
     * @param $post_args
     * @param $fields
     *
     * @return bool
     */
    public function attachment_meta_still_posting( $post_args, $fields ) {
        $still_posting_meta = false;

        foreach ( $fields as $attachment_field ) {
            $posting_field      = isset( $post_args['meta'][ $attachment_field ][0]['value'] )
                                  && $post_args['meta'][ $attachment_field ][0]['value'] === null;
            $still_posting_meta = $still_posting_meta || $posting_field;

            $field_meta_key     = $attachment_field . '_meta';
            $posting_meta       = isset( $post_args['meta'][ $field_meta_key ][0]['value'] )
                                  && $post_args['meta'][ $field_meta_key ][0]['value'] === null;
            $still_posting_meta = $still_posting_meta || $posting_meta;
        }

        return $still_posting_meta;
    }

    /**
     * Get the attachment meta array
     *
     * @param $post_args
     * @param $attachment_field
     *
     * @return array
     */
    public function get_attachment_meta( $post_args, $attachment_field ) {
        // Missing attachment value
        if ( empty( $post_args['meta'][ $attachment_field ][0]['value'] ) ) {
            // error_log('proud:get_attachment_meta 1 ' . json_encode($post_args));
            // error_log('proud:get_attachment_meta 1.1 ' . json_encode($attachment_field));
            return [];
        }

        $field_meta_key = $attachment_field . '_meta';

        if ( empty( $post_args['meta'][ $field_meta_key ][0]['value'] ) ) {
            // @TODO load these from fid?
            // var_dump( 'get_attachment_meta(): no meta' );
            // error_log('proud:get_attachment_meta 2');
            return [];
        }

        // Decode meta field
        try {
            $meta = json_decode( $post_args['meta'][ $field_meta_key ][0]['value'], true );
        } catch ( \Exception $e ) {
            error_log( $e );
            // error_log('proud:get_attachment_meta 3');
            return [];
        }

        // Legacy meta fields missing info
        if ( empty( $meta['url'] ) ) {
            if ( $post_args['post_type'] === 'document' ) {
                // Use document url
                $meta['url'] = $post_args['meta'][ $attachment_field ][0]['value'];
            } else {
                // @TODO anything?
                // var_dump( 'get_attachment_meta(): no url' );
                // error_log('proud:get_attachment_meta 4');
                return [];
            }
        }

        // error_log('proud:get_attachment_meta 5');

        return $meta;
    }

    /**
     * Ensure data is all set
     *
     * @param $meta
     *
     * @return bool
     */
    public function attachment_meta_suitable( $meta ) {
        // Don't have suitable data
        if ( empty( $meta['mime'] ) || empty( $meta['size'] ) ) {
            // error_log('proud:attachment_meta_suitable 1');
            return false;
        }

        // error_log('proud:attachment_meta_suitable 1.1');

        // Don't index
        if ( ! in_array( $meta['mime'], $this->EPDocuments->get_allowed_ingest_mime_types() ) ) {
            // error_log('proud:attachment_meta_suitable 2');
            return false;
        }

        // error_log('proud:attachment_meta_suitable 3');

        // Legacy document meta, process
        if ( empty( $meta['size_bytes'] ) ) {

            // Check size for transmission limit
            $is_small_mb = strripos( $meta['size'], 'mb' )
                           && (int) preg_replace( '/[^0-9]/', '', $meta['size'] ) < ATTACHMENT_MAX;
            if ( $is_small_mb || strripos( $meta['size'], 'kb' ) || strripos( $meta['size'], ' b' ) ) {
                // error_log('proud:attachment_meta_suitable 4');
                return true;
            }

            // error_log('proud:attachment_meta_suitable 5');
            return false;
        }

        return (int) $meta['size_bytes'] < ATTACHMENT_MAX * 1000000;
    }

    /**
     * Try to process attachments
     *
     * @param $post_args
     * @param $fields
     *
     * @return bool
     */
    public function process_attachments( &$post_args, $fields ) {
        // Trying to stop autosave
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            // error_log('proud:process_attachments 1');
            return false;
        }
        // Trying to stop autosave
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            // error_log('proud:process_attachments 2');
            return false;
        }
        // Meta values still posting (new documents seem to have multiple post points)
        if ( $this->attachment_meta_still_posting( $post_args, $fields ) ) {
            // var_dump( 'process_attachments(): still posting some fields' );
            // error_log('proud:process_attachments 3');
            return false;
        }

        $post_args['attachments'] = [];
        $attachments_meta         = [];

        foreach ( $fields as $attachment_field ) {
            $meta = $this->get_attachment_meta( $post_args, $attachment_field );
            // error_log('proud:process_attachments 4: ' . json_encode( $meta ));
            if ( ! $this->attachment_meta_suitable( $meta ) ) {
                // error_log('proud:process_attachments 5');
                continue;
            }

            $post_args['attachments'] = [ $meta['url'] ];
            $attachments_meta         = [ $meta ];
        }

        if ( ! empty( $post_args['attachments'] ) ) {
            // error_log('proud:process_attachments 6');
            $this->post_to_helper_api( $post_args, $attachments_meta );

            // Don't overwrite whats already in there
            unset( $post_args['attachments'] );

            return true;
        }

        // Not suitable for indexing
        return false;
    }

    /**
     * Add thumbnail image markup
     */
    public function process_thumbnails( &$post_args ) {
        if ( ! get_post_thumbnail_id( $post_args['ID'] ) ) {
            return;
        }

        if ( empty( $post_args['meta'] ) ) {
            $post_args['meta'] = [];
        }

        $images = [
            'default' => get_the_post_thumbnail( $post_args['ID'] ),
            'card-thumb' => get_the_post_thumbnail( $post_args['ID'], 'card-thumb' ),
            'large' => get_the_post_thumbnail( $post_args['ID'], 'large' ),
            'featured-teaser' => get_the_post_thumbnail( $post_args['ID'], 'featured-teaser' ),
        ];
        
        $post_args['meta']['post_thumbnails'] = [
            [
                'value' => json_encode($images),
                'raw' => "0",
                'long' => 0,
                'double' => (float) 0,
                'boolean' => false,
                'date' => "1971-01-01",
                'datetime' => "1971-01-01 00:00:01",
                'time' => "00:00:01",
            ]
        ];
    }

    /**
     * Alters outgoing post sync
     */
    public function ep_post_sync_args_post_prepare_meta( $post_args ) {
        // Events are returning un-desireable results due to html
        // we get weird full html markup in results
        if ( $post_args['post_type'] === 'event' ) {
            $post = get_post( $post_args['ID'] );
            if ( $post && isset( $post->post_content ) ) {
                $post_args['post_content'] = $post->post_content;
            }
        }

        $this->process_thumbnails($post_args);

        // error_log('proud:ep_post_sync_args_post_prepare_meta 1');

        // IF we're processing attachments
        if ( $this->attachments_api ) {
            // error_log('proud:ep_post_sync_args_post_prepare_meta 2');
            if ( ! empty( $this->attachments[ $post_args['post_type'] ] ) ) {
                // error_log('proud:ep_post_sync_args_post_prepare_meta 3');
                // post_type has entry in $attachments, so handle
                $this->process_attachments( $post_args, $this->attachments[ $post_args['post_type'] ] );

                // Allow CLI to put_mapping, index everything and have search still work
                if ( $this->force_attachments ) {
                    $post_args['attachments'] = [];
                }
            } else {
                // Make sure non-attachment items get a blank entry
                $post_args['attachments'] = [];
            }
        }

        return $post_args;
    }

    /**
     * Alters outgoing post url
     *
     * @param $url
     *
     * @return string
     */
    public function ep_index_post_request_path( $url, $post ) {
        if ( ! $this->attachments_api || empty( $this->attachments[ $post['post_type'] ] ) || $this->force_attachments ) {
            return $url;
        }

        // Convert to update
        return $url . '/_update';
    }

    /**
     * Alters outgoing post request params
     *
     * @param $request_args
     *
     * @return array
     */
    public function ep_index_post_request_args( $request_args, $post ) {
        if ( ! $this->attachments_api || empty( $this->attachments[ $post['post_type'] ] ) || $this->force_attachments ) {
            return $request_args;
        }

        // Convert to upsert
        $request_args['method'] = 'POST';
        $request_args['body'] = '{ "doc" : ' . $request_args['body'] . ',  "doc_as_upsert" : true }';
        return $request_args;
    }


    /**
     * Add attachment field for search
     *
     * @param $search_fields
     *
     * @since  2.3
     * @return array
     */
    public function ep_search_fields( $search_fields ) {
        if ( ! is_array( $search_fields ) ) {
            return $search_fields;
        }

        $search_fields[] = 'attachments.attachment.content';

        return $search_fields;
    }

    /**
     * Attaches global elastic integation query items
     */
    public function query_args( &$query_args, $config = [] ) {
        // error_log('elastic query_args 1: '
        // //  . json_encode([ $query_args, $config ], JSON_PRETTY_PRINT)
        // );
        $query_args['proud_ep_integrate'] = true;
        // Set to all be be processed by ep_global_alias
        $query_args['site__in'] = 'all';
        if ( 'full' !== $this->agent_type ) {
            // error_log('elastic query_args hit EXIT');
            return;
        }
        // error_log('elastic query_args hit 2');
        // Filter for certain site index from form
        $filter_index = ! empty( $config['form_instance']['filter_index'] )
                        && 'all' !== $config['form_instance']['filter_index'];
        if ( $filter_index ) {
            $query_args['filter_index'] = $config['form_instance']['filter_index'];
        } 

        // error_log('elastic query_args hit 3');
        
        // Filter for site index by teaser settings
        if ( ! empty( $config['options']['elastic_index'] ) ) {
            // error_log('elastic query_args hit 4');
            $query_args['filter_index'] = $config['options']['elastic_index'] === 'all'
                ? $this->ep_global_alias_full( true )
                : $config['options']['elastic_index'];

            // Add external categories?
            $addExternalCats = $config['options']['elastic_index'] !== $this->index_name
                && ! empty( $config['options']['external_categories'] );
            if ( $addExternalCats ) {
                // error_log('elastic query_args hit 5');
                try {
                    $terms = explode(
                        ',', 
                        preg_replace( '/[^0-9\,]/', '', sanitize_text_field( $config['options']['external_categories'] ) )
                    );
                } catch(\Exception $e) {
                    return;
                }

                if ( empty ( $query_args['tax_query'][0]['terms'] ) ) {
                    // error_log('elastic query_args hit 6');
                    $taxonomy = \Proud\Core\TeaserList::taxonomy_name( $query_args['post_type'] );
                    $query_args['tax_query'] = [
                        [
                          'taxonomy' => $taxonomy,
                          'field'    => 'term_id',
                          'terms'    => [],
                          'operator' => 'IN',
                        ]
                      ];    
                }

                foreach($terms as $term) {
                    $query_args['tax_query'][0]['terms'][] = (int) $term;
                }
            }
        }

        // error_log('elastic query_args hit LAST ' . json_encode([ $query_args, $config ], JSON_PRETTY_PRINT));
    }

    /**
     * Alters query to add our flag
     * Needed because default ep_integrate flag will scrub out our 's' query
     * sites values:
     * 'current' = this site
     * 'all' = network
     * (int) id = specific
     * (array) [id, id] = multiple
     */
    public function query_alter( $query_args, $config = [] ) {
        // Proud search query ?
        $run_elastic = ! empty( $query_args['proud_search_ajax'] ) // ajax search
                       || ! empty( $query_args['proud_teaser_search'] ) // site search
                       || ! empty( $query_args['proud_teaser_query'] ) // teaser listings
                       || ! empty( $config['options']['elastic_index'] ); // teaser listing with index

        // echo json_encode($query_args);

        // error_log('elastic query_alter 2: ' . ($run_elastic ? 'RUNNING' : 'NOPE!!!!!') . ' -- ' . json_encode([ $query_args, $config ], JSON_PRETTY_PRINT));

        // if (!$run_elastic) {
        //     echo json_encode([ $query_args, $config ]);
        // }

        if ( $run_elastic ) {
            // Ajax search
            if ( ! empty( $query_args['proud_search_ajax'] ) ) {
                $this->query_args( $query_args );
            } // Add aggregations ?
            else if ( ! empty( $config['type'] ) ) {
                $this->query_args( $query_args, $config );
                // Is search
                if ( ! empty( $query_args['proud_teaser_search'] ) ) {
                    if ( ! empty( $config['form_id_base'] ) ) {
                        $this->forms[]      = $config['form_id_base'];
                        $query_args['aggs'] = [
                            'name'       => 'search_aggregation',
                            // (can be whatever you'd like)
                            'use-filter' => true,
                            // (*bool*) used if you'd like to apply the other filters (i.e. post type, tax_query)
                            'aggs'       => [
                                'post_type' => [
                                    'terms' => [
                                        'field' => "post_type.raw",
                                    ],
                                ],
                            ],
                        ];
                    }
                } // Teaser listing
                else {
                    // We're an event date field, alter the ordering
                    if ( ! empty( $query_args['meta_key'] ) && $query_args['meta_key'] === EVENT_DATE_FIELD ) {
                        $query_args['orderby'] = 'meta.' . EVENT_DATE_FIELD . '.datetime';
                    }

                    // We're a meeting date field, alter the ordering
                    if ( ! empty( $query_args['meta_key'] ) && $query_args['meta_key'] === MEETING_DATE_FIELD ) {
                        $query_args['orderby'] = 'meta.' . MEETING_DATE_FIELD . '.datetime';
                    }

                    // Alter category listings?
                    $alter_cats = ! empty( $config['taxonomy'] )
                                  && ! empty( $config['form_id_base'] );
                    if ( $alter_cats ) {
                        // Add to our form alters
                        $this->forms[] = $config['form_id_base'];
                        // Should we modify taxonomy query?

                        if ( ! empty( $config['form_instance']['filter_categories'] ) ) {
                            $query_args['tax_query'] = [
                                [
                                    'taxonomy' => $config['taxonomy'],
                                    'field'    => 'name',
                                    // convert & -> &amp; as that's how its being stored in elastic
                                    'terms'    => array_map( function ( $val ) {
                                        return stripcslashes( htmlentities( $val ) );
                                    }, $config['form_instance']['filter_categories'] ),
                                    'operator' => 'IN',
                                ]
                            ];
                        }
                        // Add query aggregation
                        $query_args['aggs'] = [
                            'name'       => 'terms_aggregation',
                            // (can be whatever you'd like)
                            'use-filter' => true,
                            // (*bool*) used if you'd like to apply the other filters (i.e. post type, tax_query)
                            'aggs'       => [
                                'categories' => [
                                    'terms' => [
                                        'size'  => 100,
                                        'field' => 'terms.' . $config['taxonomy'] . '.name.raw',
                                    ],
                                ],
                            ],
                        ];
                    }
                }
            }
        }

        // @TODO debug
        // echo '<h2>pc query_alter $query_args</h2><pre>' . htmlspecialchars(json_encode($query_args, JSON_PRETTY_PRINT)) . '</pre>';        

        return $query_args;
    }

    /**
     * Returns enabled: true when our flag is set to activate ElasticPress
     */
    public function ep_enabled( $enabled, $query ) {
        if ( isset( $query->query_vars['proud_ep_integrate'] ) && true === $query->query_vars['proud_ep_integrate'] ) {
            $enabled = true;
        }

        // error_log('elastic ep_enabled 1: ' . $enabled);//json_encode([  ], JSON_PRETTY_PRINT));

        return $enabled;
    }

    /**
     * Filter fuzziness for match query
     *
     * @hook ep_{$indexable_slug}_match_fuzziness
     * @since 4.3.0
     * @param {string|int} $fuzziness      Fuzziness
     * @param {array}      $search_fields  Search fields
     * @param {array}      $query_vars     Query variables
     * @return {string} New fuzziness
     */
    public function ep_post_match_fuzziness( $fuzziness, $search_fields, $query_vars ) {
        // @TODO debug
        // var_dump('ep_post_match_fuzziness IS HAPPENING NOW');
        return $fuzziness;
    }

    /**
	 * Conditionally disable decaying by date
	 *
	 * @param bool  $is_decaying_enabled Whether decay by date is enabled or not
	 * @param array $settings            Settings
	 * @param array $args                WP_Query args
	 * @return bool
	 */
    public function ep_is_decaying_enabled ( $is_decaying_enabled, $settings, $args ) {
        // @TODO enable this for articles only?
        return false;
    }

    /**
     * Filter whether to enable weighting configuration
     *
     * @hook ep_enable_do_weighting
     * @since 4.2.2
     * @param  {bool}  Whether to enable weight config, defaults to true for search requests that are public or REST
     * @param  {array} $weight_config Current weight config
     * @param  {array} $args WP Query arguments
     * @param  {array} $formatted_args Formatted ES arguments
     * @return  {bool} Whether to use weighting configuration
     */
    public function ep_enable_do_weighting( $do_weighting, $weight_config, $args, $formatted_args ) {
        // @TODO better to integrate with this?
        return false;
    }

    /**
     * Filter weighting defaults for post type
     *
     * @hook ep_weighting_default_post_type_weights
     * @param  {array} $post_type_defaults Current weighting defaults
     * @param  {string} $post_type Current post type
     * @return  {array} New defaults
     */
    public function ep_weighting_default_post_type_weights( $post_type_defaults, $post_type ) {
        // @TODO implement this instead?
        return $post_type_defaults;
    }

    /**
     * Search weight
     *
     * @param  array $formatted_args
     * @param  array $args
     *
     * @since  2.1
     * @return array
     */
    public function ep_weight_search( $formatted_args, $args ) {
        // @TODO debug
        // echo '<h2>pc ep_weight_search $formatted_args</h2><pre>' . htmlspecialchars(json_encode($formatted_args, JSON_PRETTY_PRINT)) . '</pre>';
        if ( ! empty( $args['s'] ) ) {

            // Boost title ?
            // @TODO debug
            // var_dump($formatted_args['query']['bool']['should'][0]['multi_match']['fields']);
            // @TODO debug
            // var_dump($formatted_args['query']['bool']['should'][0]['multi_match']['fields'][0]);
            $boost_title = ! empty( $formatted_args['query']['bool']['should'][0]['multi_match']['fields'][0] )
                           && strpos( $formatted_args['query']['bool']['should'][0]['multi_match']['fields'][0], 'post_title' ) !== false;

            if ( $boost_title ) {
                $formatted_args['query']['bool']['should'][0]['multi_match']['fields'][0] = 'post_title^3';

                // We processing attachments?
                if ( $this->attachments_api ) {
                    // Drop importance of attachements a bit
                    if ($formatted_args['query']['bool']['should'][0]['multi_match']['fields'][3] === 'attachments.attachment.content') {
                        $formatted_args['query']['bool']['should'][0]['multi_match']['fields'][3] = 'attachments.attachment.content^0.75';
                    }
                }
            }

            // We're searching attachments + this is a content listing,
            if ( $this->attachments_api && empty( $args['proud_teaser_search'] ) && empty( $args['proud_search_ajax'] ) ) {

                // Get rid of other sorting on content listings if there is a search
                if ( empty( $formatted_args['sort'][0]['_score'] ) ) {
                    $formatted_args['sort'][]  = $formatted_args['sort'][0];
                    $formatted_args['sort'][0] = [
                        '_score' => [ 'order' => 'desc' ],
                    ];
                }

                // Drop fuzzy searching
                $drop_fuzzy = ! empty( $formatted_args['query']['bool']['should'][1]['multi_match']['fuzziness'] );
                    // && $formatted_args['query']['bool']['should'][2]['multi_match']['fuzziness'] > 0
                if ( $drop_fuzzy ) {
                    $formatted_args['query']['bool']['should'][1]['multi_match']['fuzziness'] = 0;
                }
            }

            if ( $this->ep_is_decaying_enabled( false, [], $args ) === false ) {
                $weight_search = [
                    'function_score' => [
                        'query'     => $formatted_args['query'],
                        'functions' => []
                    ]
                ];

                // Add some weighting for menu_order
                $weight_search['function_score']['functions'][] = [
                    'linear' => [
                        'menu_order' => [
                            'origin' => 0,
                            'scale'  => 1000,
                            'decay'  => 0.8,
                        ]
                    ]
                ];

                // Boost content types (normal search)
                if ( ! empty( $args['proud_teaser_search'] ) || ! empty( $args['proud_search_ajax'] ) ) {

                    // reset sort on search
                    if ( ! empty( $formatted_args['sort'] ) ) {
                        $formatted_args['sort'] = [];
                        $formatted_args['sort'][0] = [
                            '_score' => [
                                'order' => 'desc'
                            ],
                        ];
                    } 

                    // Boost values for post type
                    $post_type_boost = [
                        'agency'         => 2,
                        'question'       => 1.9,
                        'payment'        => 1.9,
                        'issue'          => 1.9,
                        'page'           => 1.3,
                        'event'          => 1.2,
                        'proud_location' => 1.1
                    ];

                    foreach ( $post_type_boost as $name => $boost ) {
                        $weight_search['function_score']['functions'][] = [
                            'filter' => [
                                'term' => [
                                    'post_type.raw' => $name
                                ]
                            ],
                            'weight' => $boost
                        ];
                    }

                    // Add weighting for events
                    // Add some weighting for menu_order
                    $weight_search['function_score']['functions'][] = [
                        'gauss' => [
                            'meta.' . EVENT_DATE_FIELD . '.date' => [
                                'scale'  => '10d',
                                'offset' => '5d',
                                'decay'  => 0.5
                            ]
                        ]
                    ];
                }

                $formatted_args['query'] = $weight_search;
            }
        }

        // We processing attachments?
        if ( $this->attachments_api ) {

            // Add highlighting for attachments
            $formatted_args['highlight'] = [
                'fields' => [
                    'attachments.attachment.content' => new stdClass,
                    'post_content'                   => [
                        'type' => 'plain',
                    ],
                ]
            ];

            // But also don't return the source since we don't want to be transmitting
            // 3mb of document
            $formatted_args['_source'] = [
                'excludes' => [ 'attachments*data', 'attachments*content' ]
            ];
        } 
        // else {
        //     // Add highlighting for attachments
        //     $formatted_args['highlight'] = [
        //         'fields' => [
        //             'post_content' => [
        //                 'type' => 'plain',
        //             ],
        //         ]
        //     ];
        // }

        // A make sure sort doesn't break on "field doesn't exist"
        if ( ! empty( $formatted_args['sort'] ) ) {
            foreach ( $formatted_args['sort'] as $outer_key => &$sort_outer ) {
                foreach ( $sort_outer as $inner_key => &$sort ) {
                    // See if we're using a _meta.{field}.{type}
                    $sections = explode( '.', $inner_key );
                    if ( ! empty( $sections[2] ) ) {
                        $sort['unmapped_type'] = $sections[2];
                    }
                }
            }
        }

        // Boost values for local results
        $formatted_args['indices_boost'] = [
            [ $this->index_name => 1.1 ]
        ];

        // var_dump( $formatted_args);

        return $formatted_args;
    }

    /**
     * Search Request path
     *
     * @param  strong $formatted_args
     * @param  array $args
     * @param  string $scope
     * @param  array $query_args
     *
     * @since  2.1
     * @return string
     */
    public function ep_search_request_path( $path, $index, $type, $query, $query_args ) {
        if ( ! empty( $query_args['filter_index'] ) ) {
            $path = $query_args['filter_index'] . '/post/_search';
        }

        return $path;
    }

    // do_action( 'ep_retrieve_raw_response', $request, $args, $scope, $query_args );

    /**
     * Save the aggregation results from the last executed query.
     *
     * @param $aggregations
     */
    public static function ep_retrieve_aggregations( $aggregations ) {
        self::$aggregations = $aggregations;
    }

    /**
     * Modify teaser settings to allow certain index to be searched
     */
    public function proud_teaser_settings( $settings, $post_type = false ) {
        if ( ! $post_type ) {
            return $settings;
        }
        if ( ! empty( $this->attachments[ $post_type ] ) || $post_type === 'post' || $post_type === 'event' ) {
            // Add option to manually input categories
            $settings['external_categories'] = [
                '#title'         => __( '(Advanced) External category ids', 'proud-teaser' ),
                '#type'          => 'text',
                '#default_value' => '',
                '#description'   => 'In order to add categories from an external site, navigate to that site (https://subsite.org), locate the Post Type (Documents, Meetings, etc) in the left admin sidebar, click into the "Categories" section, navigate to the posts you care about, and find the "tag_ID" number in the url.  Enter all desired values separated by commas ex. 123,456,789',
                '#states' => [
                    'visible' => [
                        'elastic_index' => [
                            'operator' => '!=',
                            'value' => [$this->index_name],
                            'glue' => '||'
                        ],
                    ],
                ],
            ];

            $options = array_map( function ( $o ) {
                return $o["name"];
            }, $this->search_cohort );
            // @TODO make the integration automatic for single site installs
            // Mod index name
            $options[ $this->index_name ] = __( 'This site only', 'wp-proud-search-elastic' );
            $options['all']               = __( 'All Sites', 'wp-proud-search-elastic' );
            $settings['elastic_index']    = [
                '#title'         => __( 'Content source', 'proud-teaser' ),
                '#type'          => 'radios',
                '#options'       => $options,
                '#default_value' => $this->index_name,
                '#description'   => 'Where should this content be served from?'
            ];
        }

        return $settings;
    }

    /**
     * Modify teaser settings to allow certain index to be searched
     */
    public function proud_teaser_extra_options( $options, $instance ) {
        if ( ! empty( $instance['external_categories'] ) ) {
            $options['external_categories'] = $instance['external_categories'];
        }

        if ( ! empty( $instance['elastic_index'] ) ) {
            $options['elastic_index'] = $instance['elastic_index'];
        }

        return $options;
    }

    /**
     * Alter search teaser post display settings
     */
    public function proud_teaser_post_type($post_type, $query_args) {
        if ( ! empty( $query_args['s'] ) ) {
            return 'search';
        }
        return $post_type;
    }

    /**
     * Alter search teaser display settings
     */
    public function proud_teaser_display_type($display_type, $query_args) {
        if ( ! empty( $query_args['s'] ) ) {
            return 'search';
        }
        return $display_type;
    }

    /**
     * Alters filter markup from proud teaser
     *
     * @param array $filters
     * @param array $config [ 'type' => post_type, 'options' => extra_options ]
     *
     * @return array
     */
    public function proud_teaser_filters( $filters, $config ) {
        if ( 'full' === $this->agent_type ) {
            // Add index filter?
            $site_filter = 'search' === $config['type'];
            if ( $site_filter ) {
                $options = [
                    'all' => __( 'All Sites', 'wp-proud-search-elastic' )
                ];
                // Add in our cohort
                $options = $options + array_map( function ( $o ) {
                        return $o["name"];
                    }, $this->search_cohort );
                $index   = [
                    'filter_index' => [
                        '#title'         => __( 'Search Site', 'proud-teaser' ),
                        '#type'          => 'radios',
                        '#options'       => $options,
                        '#default_value' => 'all',
                        '#description'   => ''
                    ]
                ];
                $filters = $filters + $index;
            }
        }

        return $filters;
    }

    /**
     * Alter filters output, add aggregation
     *
     * @param  array $fields
     * @param  array $instance
     * @param  string $form_id_base
     *
     * @since  2.1
     * @return array
     */
    public function form_filled_fields( $fields, $instance, $form_id_base ) {
        // Alter the form
        if ( in_array( $form_id_base, $this->forms ) ) {
            // Taxonomy filters?
            if ( ! empty( $fields['filter_categories'] ) ) {
                // We have aggregations
                if ( ! empty( self::$aggregations['terms_aggregation']['categories']['buckets'] ) ) {
                    $options = [];
                    foreach ( self::$aggregations['terms_aggregation']['categories']['buckets'] as $key => $term ) {
                        // convert &amp; -> &
                        $key             = stripcslashes( html_entity_decode( $term['key'] ) );
                        $options[ $key ] = $key . ' (' . $term['doc_count'] . ')';
                    }
                    $fields['filter_categories']['#options'] = $options;
                } // Alter tax to use Name
                else {
                    foreach ( $fields['filter_categories']['#options'] as $key => $term ) {
                        $fields['filter_categories']['#options'][ $term ] = $term;
                        unset( $fields['filter_categories']['#options'][ $key ] );
                    }
                }
            }
            // Post types
            if ( ! empty( $fields['filter_post_type'] ) ) {
                // We have aggregations
                if ( ! empty( self::$aggregations['search_aggregation']['post_type']['buckets'] ) ) {
                    // Add all tag no matter what
                    $options = [ 'all' => $fields['filter_post_type']['#options']['all'] ];
                    foreach ( self::$aggregations['search_aggregation']['post_type']['buckets'] as $key => $term ) {
                        $options[ $term['key'] ] = $fields['filter_post_type']['#options'][ $term['key'] ]
                                                   . ' (' . $term['doc_count'] . ')';
                    }
                    $fields['filter_post_type']['#options'] = $options;
                }
            }
        }

        return $fields;
    }

    /**
     * Search page filters
     */
    public function search_page_template( $path ) {
        return plugin_dir_path( __FILE__ ) . '../templates/search-page.php';
    }

    /**
     * Alters posts returned from elastic server
     */
    public function ep_retrieve_the_post( $post, $hit ) {
        // @TODO debug
        // echo '<pre>HI THERER' . htmlspecialchars(json_encode($hit, JSON_PRETTY_PRINT)) . '</pre>';
        // Deal with highlights
        if ( ! empty( $hit['highlight'] ) ) {
            $post['search_highlight'] = [];
            foreach ( $hit['highlight'] as $key => $value ) {
                $text = implode( ' <span class="search-seperator">...</span> ', array_slice( $value, 0, 10 ) );
                if ( $key === 'attachments.attachment.content' ) {
                    $post['search_highlight']['attachments'] = $text;
                } else {
                    $post['search_highlight'][ $key ] = $text;
                }
            }

            unset( $post['highlight'] );
        }

        $post['site_id'] = $hit['_index'];

        // error_log('proud:ep_retrieve_the_post 222222: ' . json_encode([ $post, $hit ], JSON_PRETTY_PRINT));

        return $post;
    }

    /**
     * Makes sure our values set above are on the post
     */
    public function ep_search_post_return_args( $args ) {
        $args[] = 'search_highlight';
        // Prevent post_content from being supplanted by highlight
        // $args = array_filter( $args, function ( $fieldKey ) {
        //     return $fieldKey !== 'post_content111';
        // } );

        return $args;
    }

    /**
     * Alter search teaser thumbnail check for elastic results
     */
    public function proud_teaser_has_thumbnail( $thumbnail ) {
        global $post;
        if ( ! $this->is_local( $post ) && ! empty( $post->meta['post_thumbnails'][0]['value'] ) ) {
            return true;
        }

        return $thumbnail;
    }

    /**
     * Alter search teaser thumbnail for elastic results
     */
    public function proud_teaser_thumbnail( $thumbnail, $size ) {
        global $post;
        if ( ! $this->is_local( $post ) && ! empty( $post->meta['post_thumbnails'][0]['value'] ) ) {
            try {
                $thumbnails = json_decode($post->meta['post_thumbnails'][0]['value']);
                $thumbnail = is_string( $size ) && ! empty ($thumbnails->{$size} )
                    ? $thumbnails->{$size}
                    : ( ! empty( $thumbnails->default ) ? $thumbnails->default : '' );
            } catch(\Exception $e) {
                // do nothing
            }
        }

        return $thumbnail;
    }

    /**
     * Alters posts returned from elastic server
     */
    public function the_title( $title, $id ) {
        global $post;
        if ( ! $this->is_local( $post ) ) {
            $title = '<span class="title-span">' . $title . '</span>' . $this->append_badge( $post->site_id );
        }

        return $title;
    }

    /**
     * Alters posts returned from elastic server
     */
    public function post_class( $classes, $class, $ID ) {
        global $post;
        if ( ! $this->is_local( $post ) ) {
            $classes[] = 'external-post';
        }

        return $classes;
    }

    /**
     * Alters posts returned from elastic server
     */
    public function post_link( $permalink, $post ) {
        if ( ! $this->is_local( $post ) ) {
            $permalink = $post->permalink;
        }

        return $permalink;
    }

    /**
     * Alters posts returned from elastic server
     */
    public function search_page_message( $message ) {
        $alert = __( 'You are currently searching the ' . $this->cohort_name( $this->index_name ) . ' site, please visit the main site to search all content.' );

        return $message . '<div class="alert alert-success">' . $alert . '</div>';
    }

    /**
     * Helper tests if content is from this site
     */
    public function is_local( $post ) {
        return empty( $post->site_id ) || $post->site_id === $this->index_name;
    }

    /**
     * Returns cohort name
     */
    public function cohort_name( $id ) {
        return $this->search_cohort[ $id ]['name'];
    }

    /**
     * Returns cohort url
     */
    public function cohort_url( $id ) {
        return $this->search_cohort[ $id ]['url'];
    }

    /**
     * Returns cohort color
     */
    public function cohort_color( $id ) {
        return $this->search_cohort[ $id ]['color'];
    }

    /**
     * Alters search post url to match elastic source
     */
    public function search_post_url( $url, $post ) {
        if ( ! $this->is_local( $post ) ) {
            return $post->permalink;
        }

        return $url;
    }

    public function append_badge( $id ) {
        if ( empty( $id ) ) {
            return '';
        }

        return '<span class="label" style="background-color:'
               . $this->cohort_color( $id ) . '">'
               . $this->cohort_name( $id ) . '</span>';
    }

    public function teaser_search_matching( $post ) {
        if ( empty( $post->search_highlight ) ) {
            return;
        }
        $allowable_tags = '<span><em>';
        include( plugin_dir_path( __FILE__ ) . '../templates/teaser-search-matching.php' );
    }

    /**
     * Alters search post args for results
     *
     * $arg[0] = (string) url
     * $arg[1] = (string) data attributes
     * $arg[2] = (string) title
     * $arg[3] = (string) appended string
     */
    public function search_post_args( $args, $post ) {
        // @TODO better way to filter
        if ( $args[2] !== 'See more' ) {
            if ( ! $this->is_local( $post ) ) {
                $args[1] = '';
                $args[3] = $this->append_badge( $post->site_id );
            }
        }

        return $args;
    }

    /**
     * Alters search post args for results
     *
     * $post_arr = (array) $post_arr
     * $post = (object) wp post
     */
    public function search_ajax_post( $post_arr, $post ) {
        if ( ! $this->is_local( $post ) ) {
            $post_arr['action_attr'] = '';
            $post_arr['action_hash'] = '';
            $post_arr['action_url']  = '';
            $post_arr['type']        = 'external';
            $post_arr['suffix']      = $this->append_badge( $post->site_id );
        }

        return $post_arr;
    }


    /**
     * Get a singleton instance of the class
     *
     * @return ProudElasticSearch
     */
    public static function factory() {
        static $instance = false;

        if ( ! $instance ) {
            $instance = new self();
        }

        return $instance;
    }
}

ProudElasticSearch::factory();