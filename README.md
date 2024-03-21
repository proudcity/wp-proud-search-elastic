# Upgrade notes

- Working on getting docs posting
  - Need to figure out whats going on with filter_allowed_metas in Post:filter_allowed_metas
    - Figured this out by setting `add_filter( 'ep_meta_mode', [ $this, 'ep_meta_mode' ] );`
- Add to docs
  - Enabling elasticpress
    - Had issues with `Fatal error: Uncaught Error: Call to undefined function ElasticPress\switch_to_blog() in /app/wordpress/wp-content/plugins/elasticpress/includes/classes/IndexHelper.php:319`
      - Messed with $this->agent_type === 'full to prevent EP_IS_NETWORK from getting called on index
    - wp --allow-root elasticpress list-features
    - Currently:
      - wp --allow-root elasticpress deactivate-feature facets
      - wp --allow-root elasticpress deactivate-feature related_posts
      - wp --allow-root elasticpress deactivate-feature searchordering
    -

# wp-proud-elastic-search

Integration for elasticsearch, currently requires ElasticPress https://wordpress.org/plugins/elasticpress/

# Essential options for operation

These must be set / evaluated before the plugin will operate as expected:

### 'proud-elastic-agent-type'

Possible values:  
agent - sites that index but aren't on ProudCity.  
subsite - sites using ProudCity, but only want to search their own content.  
full - full site search capabilities

### 'proud-elastic-index-name'

This is the index name for this site. It needs to match what exists in the search cohort below.

### 'proud-elastic-search-cohort'

This defines the search group that exists within the elastic network.

In Json:

```
{
  "san-rafael-ca": {
    "name": "Main Site",
    "url": "https://san-rafael-ca.proudcity.com",
    "color": "blue"
  },
  "san-rafael-ca-parks": {
    "name": "Community Services",
    "url": "https://san-rafael-ca-parks.proudcity.com,
    "color": "orange"
  },
  "san-rafael-ca-library": {
    "name": "Library",
    "url": "https://srpubliclibrary.org/",
    "color": "green"
  }
}
```
