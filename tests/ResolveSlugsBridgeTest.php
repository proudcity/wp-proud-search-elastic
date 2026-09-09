<?php

use PHPUnit\Framework\TestCase;
use Proud\Core\CoreResolverStub;
use Proud\SearchElastic\TeaserFilterTerms;

/**
 * Tests for TeaserFilterTerms::resolve_slugs() -- the version-skew seatbelt.
 *
 * wp-proud-core and wp-proud-search-elastic are pinned independently in the
 * site template's composer.json:
 *
 *     "proudcity/wp-proud-core":           "2026.09.08.1151",
 *     "proudcity/wp-proud-search-elastic": "2026.06.02.1320",
 *
 * so a skew between them is the normal state, not an edge case. This plugin
 * calls \Proud\Core\resolve_taxonomy_filter_slugs(), which #2720 adds to
 * wp-proud-core. Deploying this plugin against an older core would be a fatal
 * "call to undefined function" on every filtered teaser query -- a white screen
 * on the news page, not a degraded filter.
 *
 * The two plugins must still ship together. This only decides what getting that
 * wrong looks like: an empty return leaves whatever tax_query process_post()
 * built rather than overriding it, so the page still renders.
 */
class ResolveSlugsBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CoreResolverStub::$return = [];
        CoreResolverStub::$calls  = [];
    }

    public function testDelegatesToCoreWhenCoreProvidesTheResolver(): void
    {
        CoreResolverStub::$return = ['public-works'];

        $this->assertSame(
            ['public-works'],
            TeaserFilterTerms::resolve_slugs(['Public Works'], 'category', true)
        );
    }

    public function testPassesTheValuesAndTaxonomyThroughUnchanged(): void
    {
        TeaserFilterTerms::resolve_slugs(['Public Works'], 'category', true);

        $this->assertSame([[['Public Works'], 'category']], CoreResolverStub::$calls);
    }

    public function testReturnsEmptyRatherThanFatallingOnAnOlderCore(): void
    {
        // The whole point: no exception, no fatal, just nothing to override with.
        $this->assertSame(
            [],
            TeaserFilterTerms::resolve_slugs(['Public Works'], 'category', false)
        );
    }

    public function testDoesNotCallCoreWhenItIsUnavailable(): void
    {
        TeaserFilterTerms::resolve_slugs(['Public Works'], 'category', false);

        $this->assertSame([], CoreResolverStub::$calls);
    }

    public function testReturnsEmptyForEmptyInputWithoutCallingCore(): void
    {
        $this->assertSame([], TeaserFilterTerms::resolve_slugs([], 'category', true));
        $this->assertSame([], TeaserFilterTerms::resolve_slugs(['public-works'], '', true));
        $this->assertSame([], CoreResolverStub::$calls);
    }

    public function testDetectsCoreAutomaticallyWhenNotTold(): void
    {
        // The stub above defines the namespaced function, so autodetection
        // should find it and delegate.
        CoreResolverStub::$return = ['everything-traffic'];

        $this->assertSame(
            ['everything-traffic'],
            TeaserFilterTerms::resolve_slugs(['Everything Traffic'], 'category')
        );
    }
}
