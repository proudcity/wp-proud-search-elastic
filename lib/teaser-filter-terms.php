<?php

/**
 * Term handling for the Elasticsearch-backed teaser filters.
 *
 * Kept out of elasticsearch.class.php on purpose: that file requires the whole
 * ElasticPress plugin at include time, which makes it untestable without a
 * WordPress bootstrap. Nothing in here touches ElasticPress.
 *
 * @author ProudCity
 */

namespace Proud\SearchElastic;

class TeaserFilterTerms
{
    /**
     * Smallest aggregation ever requested. This is the value the facet has run
     * with since it was written, so keeping it as a floor means no site can
     * come out of #2720 with fewer options than it had going in.
     */
    const AGG_SIZE_FLOOR = 100;

    /**
     * Largest aggregation we will compute for ourselves. A checkbox list is
     * unusable long before this, and the number goes straight into an ES
     * request.
     */
    const AGG_SIZE_CEILING = 1000;

    /**
     * Slack added on top of the term count. wp_count_terms() is cached, so an
     * editor adding categories must not push the site straight back over the
     * cap before the cache expires.
     */
    const AGG_SIZE_HEADROOM = 25;

    /**
     * Elasticsearch's default search.max_buckets. A filter may raise the size
     * past our own ceiling, but not past the point where ES rejects the query.
     */
    const AGG_SIZE_HARD_MAX = 65536;

    /**
     * Resolve filter values to term slugs via wp-proud-core.
     *
     * wp-proud-core and this plugin are pinned independently in the site
     * template's composer.json, so a version skew between them is the normal
     * state rather than an edge case. \Proud\Core\resolve_taxonomy_filter_slugs()
     * arrives in core with #2720, and calling it against an older core would be
     * a fatal "call to undefined function" on every filtered teaser query --
     * a white screen on the news page rather than a degraded filter.
     *
     * The two plugins still have to ship together. This only decides what
     * getting that wrong looks like: an empty return means the caller leaves
     * whatever tax_query process_post() built alone instead of overriding it,
     * so the page renders.
     *
     * @param mixed  $values    Raw filter values.
     * @param string $taxonomy  Taxonomy to resolve against.
     * @param bool   $available Whether core's resolver is present. Injectable
     *                          so the skew path is testable; do not pass it in
     *                          production code.
     * @return string[] Slugs, or [] when core cannot resolve them.
     */
    public static function resolve_slugs($values, $taxonomy, $available = null)
    {
        if (empty($values) || empty($taxonomy)) {
            return [];
        }

        if (null === $available) {
            $available = function_exists('Proud\Core\resolve_taxonomy_filter_slugs');
        }

        if (!$available) {
            return [];
        }

        return \Proud\Core\resolve_taxonomy_filter_slugs($values, $taxonomy);
    }

    /**
     * Number of term buckets to request from Elasticsearch for a filter facet.
     *
     * An ES terms aggregation returns only the top N buckets by doc_count and
     * silently discards the rest. form_filled_fields() then REPLACES the filter
     * checkboxes with those buckets, so any term past N becomes unreachable
     * through the UI while staying perfectly searchable -- which is issue
     * #2720. San Rafael has 120 categories and was being handed 100.
     *
     * @param string $taxonomy Taxonomy being aggregated.
     * @param array  $config   Teaser config, passed to the filter for context.
     * @return int
     */
    public static function aggregation_size($taxonomy, array $config = [])
    {
        $size = self::AGG_SIZE_FLOOR;

        if (!empty($taxonomy) && is_string($taxonomy)) {
            $count = wp_count_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
            ]);

            // WP_Error for an unregistered taxonomy; historically a numeric
            // string rather than an int.
            if (!is_wp_error($count) && is_numeric($count)) {
                $size = (int) $count + self::AGG_SIZE_HEADROOM;
            }
        }

        $size = min(max(self::AGG_SIZE_FLOOR, $size), self::AGG_SIZE_CEILING);

        /**
         * Number of term buckets requested from Elasticsearch for teaser filters.
         *
         * @param int    $size     Computed bucket count.
         * @param string $taxonomy Taxonomy being aggregated.
         * @param array  $config   Teaser config.
         */
        $size = (int) apply_filters('proud_teaser_elastic_agg_size', $size, $taxonomy, $config);

        // A filter may raise or lower this, but size: 0 returns no buckets at
        // all -- an empty filter UI, which is worse than the bug being fixed.
        return min(max(1, $size), self::AGG_SIZE_HARD_MAX);
    }

    /**
     * Turn aggregation buckets into checkbox options keyed by term slug.
     *
     * The buckets used to be keyed by term NAME, which made the option value a
     * name too. That is what forced the htmlentities()/html_entity_decode()
     * round trip between the facet and the query -- the index stores
     * "Arts &amp; Culture", so a query for "Arts & Culture" returns nothing --
     * and it put administrator-authored free text into the unescaped id="" and
     * name="" attributes in modules/proud-form/templates/option-box.php.
     *
     * Slugs remove both problems. The display name is looked up locally, which
     * also means a bucket left behind by a deleted term is dropped instead of
     * rendering a checkbox that filters to nothing.
     *
     * option-box.php echoes the label without escaping, so it is escaped here.
     * The decode-then-escape is deliberate and not a round trip to nowhere: the
     * stored name already contains "&amp;", and emitting that raw would render
     * a literal "&amp;" once the browser decodes it a second time.
     *
     * @param array  $buckets  Aggregation buckets, each ['key' => slug, 'doc_count' => int].
     * @param string $taxonomy Taxonomy the slugs belong to.
     * @return array<string,string> Slug => escaped "Name (count)" label.
     */
    /**
     * Narrow aggregation-derived options to the ones the widget allows.
     *
     * The aggregation runs with use-filter, so it is scoped to the matched
     * documents -- but each matched document carries EVERY category it holds,
     * not only the ones the widget selected. A post in an allowed category
     * that is also tagged "Homelessness" therefore put a Homelessness bucket
     * in the result, and form_filled_fields() used to hand those buckets
     * straight to #options, replacing the restricted list build_filters()
     * had already produced (#2923).
     *
     * That was not only cosmetic. process_post() replaced the widget's
     * tax_query with the visitor's selection rather than narrowing it
     * (PCD379), so the leaked checkbox was live: clicking it returned every
     * post in that category, including ones the widget existed to exclude.
     * Both halves are fixed together; this is the half that stops us offering
     * the choice at all.
     *
     * Returning [] when nothing intersects is deliberate. Every caller guards
     * with `if (! empty($options))`, so an empty return leaves the widget's
     * own configured list in place. The one thing that must never happen is
     * falling back to the unrestricted buckets.
     *
     * @param array<string,string> $options Slug => label, from the aggregation.
     * @param array<string,string> $allowed Slug => label, as build_filters() restricted it.
     * @return array<string,string> The intersection, in aggregation order.
     */
    public static function narrow_to_allowed(array $options, $allowed)
    {
        // No restriction to apply. build_filters() hands over every category
        // when the widget selected none, so there is nothing to narrow to and
        // the aggregation's own list is already correct.
        if (empty($allowed) || !is_array($allowed)) {
            return $options;
        }

        return array_intersect_key($options, $allowed);
    }

    public static function options_from_buckets(array $buckets, $taxonomy)
    {
        if (empty($taxonomy) || !is_string($taxonomy)) {
            return [];
        }

        $options = [];

        foreach ($buckets as $bucket) {
            if (!is_array($bucket) || empty($bucket['key'])) {
                continue;
            }

            $term = get_term_by('slug', (string) $bucket['key'], $taxonomy);
            if (!$term || empty($term->slug) || empty($term->name)) {
                // Stale bucket: the index still holds a term the database no
                // longer has.
                continue;
            }

            // Key on the RESOLVED slug, never on the raw bucket key. The lookup
            // is not an equality check: WP_Term_Query sanitises the value with
            // sanitize_title() before querying, so a bucket key of
            // "news<img src=x>" still matches the term slugged "news" -- and
            // that raw key would then land in the id="" and name="" attributes
            // that option-box.php echoes unescaped. $term->slug is canonical by
            // definition. Reaching this needs control of the Elasticsearch
            // response, but it is the exact bug class this change exists to
            // close, so it should not be reachable at all.
            $slug = $term->slug;
            if (isset($options[$slug])) {
                continue;
            }

            $name  = html_entity_decode((string) $term->name, ENT_QUOTES, 'UTF-8');
            $count = isset($bucket['doc_count']) ? (int) $bucket['doc_count'] : 0;

            $options[$slug] = esc_html($name) . ' (' . $count . ')';
        }

        return $options;
    }

    /**
     * Rekey locally-built checkbox options by term slug.
     *
     * Used when there is no aggregation to work from. The result has to be
     * keyed the same way options_from_buckets() keys its own, because
     * option-box.php decides whether a box is ticked with
     * in_array($option_key, $field['#value']) and #value holds the slugs
     * process_post() resolved. Term IDs there would render every box unticked
     * -- in_array(248, ['public-works']) is false under PHP 8 -- while the
     * results themselves stayed correct, which is a nasty thing to debug.
     *
     * Idempotent on purpose: proud-teasers.php keys by slug from #2720 on, but
     * the proud-teaser-filter-categories filter is an open extension point, so
     * this may still be handed term IDs. A plain (int) cast of a slug key gives
     * 0 and would silently drop every option.
     *
     * Labels are passed through untouched -- they come from the local term
     * list, not from a bucket key, so their encoding is already whatever the
     * rest of the form expects.
     *
     * @param array  $options  Checkbox options keyed by slug or term ID.
     * @param string $taxonomy Taxonomy the keys belong to.
     * @return array Options keyed by slug, in the order given.
     */
    public static function options_by_slug(array $options, $taxonomy)
    {
        if (empty($options) || empty($taxonomy) || !is_string($taxonomy)) {
            return $options;
        }

        $rekeyed = [];

        foreach ($options as $key => $label) {
            // Slug first: WordPress allows a fully numeric slug, and treating
            // "2024" as a term ID would look up the wrong term and drop the
            // category from the filter. Nothing is lost on the term-ID path --
            // no slug matches a bare ID unless one was deliberately created.
            $term = get_term_by('slug', (string) $key, $taxonomy);

            if (!$term && is_numeric($key)) {
                $term = get_term_by('id', (int) $key, $taxonomy);
            }

            // Keep an option we cannot resolve rather than dropping it. The
            // caller replaces #options wholesale whenever the result is
            // non-empty, so dropping on a failed lookup means one key that
            // happens to resolve can collapse the whole filter to a single
            // checkbox. A stale ES bucket is worth dropping -- it filters to
            // nothing -- but these options came from the local term list and
            // are only being rekeyed. The original key is already
            // attribute-safe: proud-teasers.php supplies a slug or an integer
            // term_id.
            $rekeyed[$term && !empty($term->slug) ? $term->slug : $key] = $label;
        }

        return $rekeyed;
    }
}
