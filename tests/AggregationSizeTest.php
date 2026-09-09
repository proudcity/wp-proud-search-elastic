<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Proud\SearchElastic\TeaserFilterTerms;

/**
 * Tests for TeaserFilterTerms::aggregation_size() -- the primary fix for #2720.
 *
 * query_alter() asked Elasticsearch for a terms aggregation with a hard-coded
 * 'size' => 100, and form_filled_fields() then REPLACED the filter checkboxes
 * with whatever buckets came back. An ES terms aggregation returns only the
 * top N buckets by doc_count, so on a site with more than 100 terms the
 * remainder are discarded without any error: their content stays indexed and
 * searchable but becomes unreachable through the filter UI.
 *
 * San Rafael has 120 categories, 106 of which produce buckets. The rendered
 * /news/ page contained exactly 100 checkboxes, and four categories with
 * published posts had no way to be selected at all -- Police Department (3
 * posts), Public Safety Center (1), Southeast San Rafael Specific Plan (1) and
 * Terra Linda Mural (1).
 *
 * The floor of 100 is deliberate: it is the value every site has been running,
 * so no site can regress. The ceiling exists because the number goes straight
 * into an ES request and a runaway taxonomy should not be able to push it past
 * search.max_buckets (65536 by default) or render a checkbox list nobody can
 * use.
 */
class AggregationSizeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('is_wp_error')->alias(static fn($thing) => $thing instanceof WP_Error);

        // Pass-through by default; individual tests override to assert on the
        // filter contract.
        Functions\when('apply_filters')->alias(static fn($hook, $value) => $value);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function withTermCount($count): void
    {
        Functions\when('wp_count_terms')->justReturn($count);
    }

    // ---------------------------------------------------------------------
    // Floor -- no site may get a smaller aggregation than it has today
    // ---------------------------------------------------------------------

    public function testASiteWithNoTermsStillAsksForTheHistoricalHundred(): void
    {
        $this->withTermCount(0);

        $this->assertSame(100, TeaserFilterTerms::aggregation_size('category'));
    }

    public function testASmallSiteStillAsksForTheHistoricalHundred(): void
    {
        // somervillenj produces 9 buckets, miamisburgoh 4. Nothing changes for
        // them.
        $this->withTermCount(9);

        $this->assertSame(100, TeaserFilterTerms::aggregation_size('category'));
    }

    public function testASiteExactlyAtTheOldCapAsksForMoreThanTheOldCap(): void
    {
        // The failure was silent precisely because 100 terms still "worked".
        $this->withTermCount(100);

        $this->assertGreaterThan(100, TeaserFilterTerms::aggregation_size('category'));
    }

    // ---------------------------------------------------------------------
    // The San Rafael case
    // ---------------------------------------------------------------------

    public function testSanRafaelAsksForMoreBucketsThanItHasCategories(): void
    {
        // 120 categories registered, 106 of which currently produce buckets.
        $this->withTermCount(120);

        $this->assertSame(145, TeaserFilterTerms::aggregation_size('category'));
    }

    public function testSanRafaelWouldReceiveEveryBucketItHas(): void
    {
        $this->withTermCount(120);

        $this->assertGreaterThanOrEqual(106, TeaserFilterTerms::aggregation_size('category'));
    }

    public function testLeavesHeadroomForTermsAddedSinceTheCountWasCached(): void
    {
        // wp_count_terms() is cached, so an editor adding categories must not
        // immediately push the site back over the cap.
        $this->withTermCount(200);

        $this->assertSame(225, TeaserFilterTerms::aggregation_size('category'));
    }

    // ---------------------------------------------------------------------
    // Ceiling
    // ---------------------------------------------------------------------

    public function testCapsTheRequestOnATaxonomyWithRunawayTermCount(): void
    {
        $this->withTermCount(50000);

        $this->assertSame(1000, TeaserFilterTerms::aggregation_size('category'));
    }

    public function testTheCeilingIsWellUnderElasticsearchMaxBuckets(): void
    {
        $this->withTermCount(PHP_INT_MAX);

        $this->assertLessThan(65536, TeaserFilterTerms::aggregation_size('category'));
    }

    // ---------------------------------------------------------------------
    // wp_count_terms() return shapes
    // ---------------------------------------------------------------------

    public function testFallsBackToTheFloorWhenTheTaxonomyIsNotRegistered(): void
    {
        // wp_count_terms() returns WP_Error for an unknown taxonomy. Reaching
        // this branch means the facet is misconfigured; asking for the old 100
        // keeps it behaving exactly as it does today rather than erroring.
        $this->withTermCount(new WP_Error('invalid_taxonomy', 'Invalid taxonomy.'));

        $this->assertSame(100, TeaserFilterTerms::aggregation_size('category'));
    }

    public function testAcceptsTheNumericStringWpCountTermsHasHistoricallyReturned(): void
    {
        $this->withTermCount('120');

        $this->assertSame(145, TeaserFilterTerms::aggregation_size('category'));
    }

    public function testFallsBackToTheFloorOnANonNumericReturn(): void
    {
        $this->withTermCount(null);

        $this->assertSame(100, TeaserFilterTerms::aggregation_size('category'));
    }

    public function testFallsBackToTheFloorWithNoTaxonomy(): void
    {
        $this->withTermCount(0);

        $this->assertSame(100, TeaserFilterTerms::aggregation_size(''));
    }

    // ---------------------------------------------------------------------
    // wp_count_terms() call contract
    // ---------------------------------------------------------------------

    public function testCountsEveryTermIncludingThoseWithNoPosts(): void
    {
        // hide_empty must be false. An empty term today is a term with posts
        // tomorrow, and the count is what sizes the request.
        Functions\expect('wp_count_terms')
            ->once()
            ->with(['taxonomy' => 'category', 'hide_empty' => false])
            ->andReturn(120);

        $this->assertSame(145, TeaserFilterTerms::aggregation_size('category'));
    }

    public function testCountsTheTaxonomyItWasAskedAbout(): void
    {
        Functions\expect('wp_count_terms')
            ->once()
            ->with(['taxonomy' => 'document_taxonomy', 'hide_empty' => false])
            ->andReturn(4);

        $this->assertSame(100, TeaserFilterTerms::aggregation_size('document_taxonomy'));
    }

    // ---------------------------------------------------------------------
    // Filter contract
    // ---------------------------------------------------------------------

    public function testTheSizeCanBeOverriddenByAFilter(): void
    {
        $this->withTermCount(120);
        Functions\when('apply_filters')->alias(
            static fn($hook, $value) => 'proud_teaser_elastic_agg_size' === $hook ? 500 : $value
        );

        $this->assertSame(500, TeaserFilterTerms::aggregation_size('category'));
    }

    public function testTheFilterReceivesTheComputedSizeAndTheTaxonomy(): void
    {
        $this->withTermCount(120);
        $seen = null;
        Functions\when('apply_filters')->alias(function ($hook, ...$args) use (&$seen) {
            if ('proud_teaser_elastic_agg_size' === $hook) {
                $seen = $args;
            }
            return $args[0];
        });

        TeaserFilterTerms::aggregation_size('category');

        $this->assertSame([145, 'category'], array_slice((array) $seen, 0, 2));
    }

    public function testAFilterCannotProduceASizeElasticsearchWillReject(): void
    {
        // size: 0 is a valid ES request but returns no buckets at all, which
        // would empty the filter UI outright -- a worse failure than #2720.
        $this->withTermCount(120);
        Functions\when('apply_filters')->alias(
            static fn($hook, $value) => 'proud_teaser_elastic_agg_size' === $hook ? 0 : $value
        );

        $this->assertSame(1, TeaserFilterTerms::aggregation_size('category'));
    }

    public function testAFilterCannotProduceANegativeSize(): void
    {
        $this->withTermCount(120);
        Functions\when('apply_filters')->alias(
            static fn($hook, $value) => 'proud_teaser_elastic_agg_size' === $hook ? -5 : $value
        );

        $this->assertSame(1, TeaserFilterTerms::aggregation_size('category'));
    }

    public function testAFilterCannotExceedElasticsearchMaxBuckets(): void
    {
        $this->withTermCount(120);
        Functions\when('apply_filters')->alias(
            static fn($hook, $value) => 'proud_teaser_elastic_agg_size' === $hook ? 999999 : $value
        );

        $this->assertSame(65536, TeaserFilterTerms::aggregation_size('category'));
    }

    public function testAlwaysReturnsAnInteger(): void
    {
        $this->withTermCount(120);
        Functions\when('apply_filters')->alias(
            static fn($hook, $value) => 'proud_teaser_elastic_agg_size' === $hook ? '250' : $value
        );

        $this->assertSame(250, TeaserFilterTerms::aggregation_size('category'));
    }
}
