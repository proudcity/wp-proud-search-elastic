<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Proud\SearchElastic\TeaserFilterTerms;

/**
 * Tests for TeaserFilterTerms::options_by_slug() -- the no-aggregation branch
 * of form_filled_fields().
 *
 * When there is no aggregation to work from, the checkbox options are whatever
 * proud-teasers.php built locally. Those have to end up keyed the same way the
 * aggregation branch keys them, because option-box.php decides whether a box is
 * ticked with in_array($option_key, $field['#value']) and #value now holds the
 * slugs process_post() resolved.
 *
 * The mismatch this guards against is not hypothetical: with slugs in #value
 * and term IDs in the option keys, in_array(248, ['public-works']) is false
 * under PHP 8, so every box renders unticked while the results are correct.
 *
 * The method has to be idempotent. proud-teasers.php keys by slug from #2720
 * on, but this branch also runs against options that a filter may have built,
 * and a naive (int) cast of an already-slug key gives 0 and drops every option.
 */
class OptionsBySlugTest extends TestCase
{
    private const TERMS = [
        248 => ['slug' => 'public-works', 'name' => 'Public Works'],
        545 => ['slug' => 'everything-traffic', 'name' => 'Everything Traffic'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_term_by')->alias(function ($field, $value, $taxonomy = '') {
            if ('category' !== $taxonomy) {
                return false;
            }
            if ('id' === $field) {
                return isset(self::TERMS[(int) $value])
                    ? (object) (self::TERMS[(int) $value] + ['term_id' => (int) $value])
                    : false;
            }
            if ('slug' === $field) {
                foreach (self::TERMS as $id => $term) {
                    if ($term['slug'] === $value) {
                        return (object) ($term + ['term_id' => $id]);
                    }
                }
            }
            return false;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testRekeysTermIdKeyedOptionsBySlug(): void
    {
        $options = TeaserFilterTerms::options_by_slug(
            [248 => 'Public Works', 545 => 'Everything Traffic'],
            'category'
        );

        $this->assertSame(
            ['public-works' => 'Public Works', 'everything-traffic' => 'Everything Traffic'],
            $options
        );
    }

    public function testLeavesAlreadySlugKeyedOptionsAlone(): void
    {
        $options = ['public-works' => 'Public Works'];

        $this->assertSame($options, TeaserFilterTerms::options_by_slug($options, 'category'));
    }

    public function testIsIdempotent(): void
    {
        $once  = TeaserFilterTerms::options_by_slug([248 => 'Public Works'], 'category');
        $twice = TeaserFilterTerms::options_by_slug($once, 'category');

        $this->assertSame($once, $twice);
    }

    public function testPreservesTheLabelExactlyAsGiven(): void
    {
        // These labels come from proud-teasers.php, not from an aggregation
        // bucket, so this method must not second-guess their encoding.
        $options = TeaserFilterTerms::options_by_slug([248 => 'Arts &amp; Culture'], 'category');

        $this->assertSame('Arts &amp; Culture', $options['public-works']);
    }

    public function testPreservesOrder(): void
    {
        $options = TeaserFilterTerms::options_by_slug(
            [545 => 'Everything Traffic', 248 => 'Public Works'],
            'category'
        );

        $this->assertSame(['everything-traffic', 'public-works'], array_keys($options));
    }

    public function testReturnsTheOptionsUntouchedWithNoTaxonomy(): void
    {
        // Better a filter keyed the old way than an empty filter.
        $options = [248 => 'Public Works'];

        $this->assertSame($options, TeaserFilterTerms::options_by_slug($options, ''));
    }

    public function testReturnsAnEmptyArrayForEmptyOptions(): void
    {
        $this->assertSame([], TeaserFilterTerms::options_by_slug([], 'category'));
    }

    public function testEveryReturnedKeyIsSafeToInterpolateIntoAnAttribute(): void
    {
        $options = TeaserFilterTerms::options_by_slug(
            [248 => 'Public Works', 545 => 'Everything Traffic'],
            'category'
        );

        foreach (array_keys($options) as $key) {
            $this->assertMatchesRegularExpression('~^[a-z0-9_-]+$~i', (string) $key);
        }
    }

    // ---------------------------------------------------------------------
    // Numeric slugs
    //
    // WordPress allows a fully numeric slug -- a category named "2024" gets
    // the slug "2024". San Rafael already has 2024-election, 930-irwin and
    // 150-year-anniversary, so a bare year is not a far-fetched addition.
    // Treating such a key as a term ID looks it up as the wrong thing and
    // drops the category from the filter entirely.
    // ---------------------------------------------------------------------

    public function testResolvesAFullyNumericSlugAsASlugNotATermId(): void
    {
        Functions\when('get_term_by')->alias(function ($field, $value, $taxonomy = '') {
            if ('slug' === $field && '2024' === (string) $value) {
                return (object) ['term_id' => 900, 'slug' => '2024', 'name' => '2024'];
            }
            // There is also a term whose ID is literally 2024, to make the
            // ambiguity real rather than theoretical.
            if ('id' === $field && 2024 === (int) $value) {
                return (object) ['term_id' => 2024, 'slug' => 'something-else', 'name' => 'Something Else'];
            }
            return false;
        });

        $options = TeaserFilterTerms::options_by_slug(['2024' => '2024'], 'category');

        // PHP coerces a numeric-string array key to an int, so the key comes
        // back as 2024 rather than '2024'. Harmless downstream: option-box.php
        // compares with a loose in_array(), and 2024 == '2024' holds under
        // PHP 8's numeric-string rules. What matters is that the key is the
        // slug and not the unrelated term that ID 2024 points at.
        $this->assertSame(['2024'], array_map('strval', array_keys($options)));
        $this->assertArrayNotHasKey('something-else', $options);
    }

    public function testStillResolvesABareTermIdWhenNoSlugMatchesIt(): void
    {
        $options = TeaserFilterTerms::options_by_slug([248 => 'Public Works'], 'category');

        $this->assertSame(['public-works'], array_keys($options));
    }

    // ---------------------------------------------------------------------
    // Never drop an option you cannot resolve
    //
    // Security review finding 3. form_filled_fields() replaces #options
    // wholesale whenever the result is non-empty, so dropping on a failed
    // lookup lets one key that happens to resolve collapse the entire filter
    // down to a single checkbox.
    // ---------------------------------------------------------------------

    public function testKeepsAnOptionWhoseTermCannotBeResolved(): void
    {
        $options = TeaserFilterTerms::options_by_slug(
            [248 => 'Public Works', 9999 => 'Unresolvable'],
            'category'
        );

        $this->assertCount(2, $options);
        $this->assertContains('Unresolvable', $options);
    }

    public function testDoesNotCollapseTheFilterWhenMostKeysFailToResolve(): void
    {
        $options = TeaserFilterTerms::options_by_slug(
            [248 => 'Public Works', 9001 => 'A', 9002 => 'B', 9003 => 'C'],
            'category'
        );

        $this->assertCount(4, $options);
    }

    public function testAKeptKeyIsStillSafeForAnUnescapedAttribute(): void
    {
        $options = TeaserFilterTerms::options_by_slug([9999 => 'Unresolvable'], 'category');

        foreach (array_keys($options) as $key) {
            $this->assertMatchesRegularExpression('~^[a-z0-9_-]+$~i', (string) $key);
        }
    }
}
