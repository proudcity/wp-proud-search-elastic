<?php

use PHPUnit\Framework\TestCase;
use Proud\SearchElastic\TeaserFilterTerms;

/**
 * Tests for TeaserFilterTerms::narrow_to_allowed() -- issue #2923.
 *
 * The category filter on a news list offered categories the widget did not
 * select. On San Rafael mini beta, /major-development-projects-news/ has 26
 * categories configured and offered "Homelessness (10)", which is not one of
 * them.
 *
 * The aggregation is declared with use-filter, so it is scoped to the matched
 * documents -- but a document carries every category it holds. 9 of the 52
 * posts matching that widget's 26 categories are also tagged Homelessness, so
 * Homelessness came back as a bucket. form_filled_fields() then handed the
 * buckets straight to #options, throwing away the restricted list that
 * build_filters() had already produced.
 *
 * Three other categories leaked on that one page the same way: City News (19),
 * Merrydale short-term shelter (1) and Community and Economic Development (1).
 *
 * This was never a #2720 regression -- git log -L over the block shows the
 * replace behaviour predates it, and the aggregation returns 22 buckets for
 * this widget, far below both the old fixed size of 100 and the computed size
 * that replaced it. Enabling Elastic exposed a defect that was already there.
 */
class NarrowToAllowedTest extends TestCase
{
    /**
     * What the aggregation produces for the reported page: the widget's own
     * categories plus the four that leaked in from co-tagged posts.
     */
    private const BUCKETS = [
        'major-development-projects' => 'Major development projects (45)',
        'city-news'                  => 'City News (19)',
        'homelessness'               => 'Homelessness (9)',
        '350-merrydale'              => '350 Merrydale affordable housing project (15)',
        'merrydale-shelter'          => 'Merrydale short-term shelter (1)',
        'ced'                        => 'Community and Economic Development (1)',
    ];

    /**
     * What build_filters() produced from proud_teaser_terms before the Elastic
     * branch overwrote it. Keyed by slug since #2720.
     */
    private const ALLOWED = [
        'major-development-projects' => 'Major development projects',
        '350-merrydale'              => '350 Merrydale affordable housing project',
    ];

    public function testDropsCategoriesTheWidgetDidNotSelect(): void
    {
        $narrowed = TeaserFilterTerms::narrow_to_allowed(self::BUCKETS, self::ALLOWED);

        $this->assertArrayNotHasKey('homelessness', $narrowed, 'the reported bug');
        $this->assertArrayNotHasKey('city-news', $narrowed);
        $this->assertArrayNotHasKey('merrydale-shelter', $narrowed);
        $this->assertArrayNotHasKey('ced', $narrowed);
    }

    public function testKeepsTheSelectedCategoriesWithTheirElasticCounts(): void
    {
        $narrowed = TeaserFilterTerms::narrow_to_allowed(self::BUCKETS, self::ALLOWED);

        // The counts are the point of using the aggregation at all, so the
        // bucket label has to survive, not be replaced by the plain name.
        $this->assertSame(
            [
                'major-development-projects' => 'Major development projects (45)',
                '350-merrydale'              => '350 Merrydale affordable housing project (15)',
            ],
            $narrowed
        );
    }

    public function testPreservesAggregationOrderRatherThanConfiguredOrder(): void
    {
        $allowed = [
            '350-merrydale'              => '350 Merrydale affordable housing project',
            'major-development-projects' => 'Major development projects',
        ];

        // Buckets arrive sorted by doc_count; the filter should still read in
        // that order rather than being reshuffled by the config.
        $this->assertSame(
            ['major-development-projects', '350-merrydale'],
            array_keys(TeaserFilterTerms::narrow_to_allowed(self::BUCKETS, $allowed))
        );
    }

    public function testReturnsEmptyWhenNothingIntersects(): void
    {
        // Empty is what the caller needs: its `if (! empty($options))` guard
        // then leaves the widget's configured list in place. The one outcome
        // that must never happen is falling back to the raw buckets.
        $this->assertSame(
            [],
            TeaserFilterTerms::narrow_to_allowed(self::BUCKETS, ['nothing-matching' => 'Nope'])
        );
    }

    public function testTermIdKeyedOptionsFromAnOlderCoreDegradeRatherThanLeak(): void
    {
        // wp-proud-core and this plugin are pinned independently. Against a
        // core from before #2720, #options is keyed by term_id while the
        // buckets are keyed by slug, so nothing intersects. Degrading to the
        // configured list without Elastic counts is acceptable; leaking every
        // bucket is not.
        $legacy = [301 => 'Homelessness', 326 => 'Major development projects'];

        $this->assertSame([], TeaserFilterTerms::narrow_to_allowed(self::BUCKETS, $legacy));
    }

    public function testUnrestrictedWidgetIsANoOp(): void
    {
        // build_filters() hands over every category when the widget selected
        // none, so there is nothing to narrow to.
        $this->assertSame(
            self::BUCKETS,
            TeaserFilterTerms::narrow_to_allowed(self::BUCKETS, [])
        );
    }

    public function testNonArrayAllowedIsTreatedAsNoRestriction(): void
    {
        $this->assertSame(
            self::BUCKETS,
            TeaserFilterTerms::narrow_to_allowed(self::BUCKETS, null)
        );
    }

    public function testEmptyBucketsStayEmpty(): void
    {
        $this->assertSame([], TeaserFilterTerms::narrow_to_allowed([], self::ALLOWED));
    }
}
