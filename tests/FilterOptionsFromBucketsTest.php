<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Proud\SearchElastic\TeaserFilterTerms;

/**
 * Tests for TeaserFilterTerms::options_from_buckets() -- the second half of
 * the #2720 fix.
 *
 * form_filled_fields() used to build the checkbox options straight out of the
 * aggregation bucket keys, which were term NAMES:
 *
 *     $key = stripcslashes(html_entity_decode($term['key']));
 *     $options[$key] = $key . ' (' . $term['doc_count'] . ')';
 *
 * Three problems, all confirmed against the live san-rafael-ca index:
 *
 * 1. Names are not unique. "Public Works" exists on San Rafael as a category,
 *    a document_taxonomy term, a staff-member-group and three faq-topics.
 * 2. Names are stored HTML-encoded, so the decode above has to be undone by a
 *    matching htmlentities() on the query side. Querying the index for
 *    "Arts & Culture" returns 0; "Arts &amp; Culture" returns 26. The two
 *    halves currently agree by coincidence, and any change to either breaks a
 *    subset of categories -- the "some filters" in the issue title.
 * 3. The key becomes the option value, and option-box.php interpolates that
 *    value into id="" and name="" WITHOUT escaping. A term name is
 *    administrator-controlled free text, so it can contain a quote.
 *
 * Aggregating on terms.<taxonomy>.slug fixes all three: slugs are unique
 * within a taxonomy, contain no entities, and sanitize_title() guarantees they
 * cannot break out of an attribute. The display name is looked up locally
 * instead of being carried in the bucket key.
 */
class FilterOptionsFromBucketsTest extends TestCase
{
    private const TERMS = [
        'public-works'       => 'Public Works',
        'everything-traffic' => 'Everything Traffic',
        'arts-culture'       => 'Arts &amp; Culture',
        'police-department'  => 'Police Department',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_term_by')->alias(function ($field, $value, $taxonomy = '') {
            if ('slug' !== $field || 'category' !== $taxonomy) {
                return false;
            }
            return isset(self::TERMS[$value])
                ? (object) ['slug' => $value, 'name' => self::TERMS[$value]]
                : false;
        });

        Functions\when('esc_html')->alias(
            static fn($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8')
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function buckets(array $pairs): array
    {
        $buckets = [];
        foreach ($pairs as $key => $count) {
            $buckets[] = ['key' => $key, 'doc_count' => $count];
        }
        return $buckets;
    }

    // ---------------------------------------------------------------------
    // Shape
    // ---------------------------------------------------------------------

    public function testKeysTheOptionsBySlug(): void
    {
        $options = TeaserFilterTerms::options_from_buckets(
            $this->buckets(['public-works' => 453]),
            'category'
        );

        $this->assertSame(['public-works'], array_keys($options));
    }

    public function testLabelsWithTheTermNameAndDocCount(): void
    {
        $options = TeaserFilterTerms::options_from_buckets(
            $this->buckets(['public-works' => 453]),
            'category'
        );

        $this->assertSame('Public Works (453)', $options['public-works']);
    }

    public function testPreservesTheBucketOrderElasticsearchReturned(): void
    {
        // Buckets arrive sorted by doc_count desc; the checkbox list has always
        // shown the busiest categories first.
        $options = TeaserFilterTerms::options_from_buckets(
            $this->buckets([
                'public-works'       => 453,
                'everything-traffic' => 278,
                'police-department'  => 3,
            ]),
            'category'
        );

        $this->assertSame(
            ['public-works', 'everything-traffic', 'police-department'],
            array_keys($options)
        );
    }

    public function testReturnsAnEmptyArrayWhenThereAreNoBuckets(): void
    {
        $this->assertSame([], TeaserFilterTerms::options_from_buckets([], 'category'));
    }

    // ---------------------------------------------------------------------
    // Entity handling -- the label must read the way an editor typed it
    // ---------------------------------------------------------------------

    public function testDisplaysAStoredAmpersandEntityAsASingleAmpersand(): void
    {
        // Stored as "Arts &amp; Culture"; the label must render "Arts & Culture"
        // in the browser, which means exactly one level of encoding on output.
        $options = TeaserFilterTerms::options_from_buckets(
            $this->buckets(['arts-culture' => 26]),
            'category'
        );

        $this->assertSame('Arts &amp; Culture (26)', $options['arts-culture']);
        $this->assertSame(
            'Arts & Culture (26)',
            html_entity_decode($options['arts-culture'], ENT_QUOTES, 'UTF-8')
        );
    }

    public function testDoesNotDoubleEncodeAStoredEntity(): void
    {
        $options = TeaserFilterTerms::options_from_buckets(
            $this->buckets(['arts-culture' => 26]),
            'category'
        );

        $this->assertStringNotContainsString('&amp;amp;', $options['arts-culture']);
    }

    // ---------------------------------------------------------------------
    // Escaping -- option-box.php echoes both the key and the label unescaped
    // ---------------------------------------------------------------------

    public function testEscapesMarkupInATermName(): void
    {
        Functions\when('get_term_by')->alias(
            static fn($field, $value, $taxonomy = '') => (object) [
                'slug' => 'evil',
                'name' => '<script>alert(1)</script>',
            ]
        );

        $options = TeaserFilterTerms::options_from_buckets(
            $this->buckets(['evil' => 1]),
            'category'
        );

        $this->assertStringNotContainsString('<script>', $options['evil']);
    }

    public function testEscapesAQuoteInATermName(): void
    {
        Functions\when('get_term_by')->alias(
            static fn($field, $value, $taxonomy = '') => (object) [
                'slug' => 'quoted',
                'name' => 'bad" onfocus=alert(1) x',
            ]
        );

        $options = TeaserFilterTerms::options_from_buckets(
            $this->buckets(['quoted' => 1]),
            'category'
        );

        $this->assertStringNotContainsString('"', $options['quoted']);
    }

    public function testEveryOptionKeyIsSafeToInterpolateIntoAnAttribute(): void
    {
        $options = TeaserFilterTerms::options_from_buckets(
            $this->buckets([
                'public-works'       => 453,
                'everything-traffic' => 278,
                'arts-culture'       => 26,
            ]),
            'category'
        );

        foreach (array_keys($options) as $key) {
            $this->assertMatchesRegularExpression('~^[a-z0-9_-]+$~i', $key);
        }
    }

    // ---------------------------------------------------------------------
    // Stale and malformed buckets
    // ---------------------------------------------------------------------

    public function testSkipsABucketWhoseTermNoLongerExists(): void
    {
        // The index outlives the database: San Rafael currently returns 106
        // buckets for 104 categories that actually have published posts.
        // Rendering a checkbox for a deleted term would show a raw slug and
        // filter to nothing.
        $options = TeaserFilterTerms::options_from_buckets(
            $this->buckets(['public-works' => 453, 'deleted-category' => 7]),
            'category'
        );

        $this->assertSame(['public-works'], array_keys($options));
    }

    public function testSkipsABucketWithNoKey(): void
    {
        $options = TeaserFilterTerms::options_from_buckets(
            [['doc_count' => 5], ['key' => 'public-works', 'doc_count' => 453]],
            'category'
        );

        $this->assertSame(['public-works'], array_keys($options));
    }

    public function testTreatsAMissingDocCountAsZero(): void
    {
        $options = TeaserFilterTerms::options_from_buckets(
            [['key' => 'public-works']],
            'category'
        );

        $this->assertSame('Public Works (0)', $options['public-works']);
    }

    public function testCastsTheDocCountToAnInteger(): void
    {
        $options = TeaserFilterTerms::options_from_buckets(
            [['key' => 'public-works', 'doc_count' => '453']],
            'category'
        );

        $this->assertSame('Public Works (453)', $options['public-works']);
    }

    public function testIgnoresANonArrayBucket(): void
    {
        $options = TeaserFilterTerms::options_from_buckets(
            ['nonsense', ['key' => 'public-works', 'doc_count' => 453]],
            'category'
        );

        $this->assertSame(['public-works'], array_keys($options));
    }

    public function testReturnsAnEmptyArrayWhenNoTaxonomyIsGiven(): void
    {
        $this->assertSame(
            [],
            TeaserFilterTerms::options_from_buckets($this->buckets(['public-works' => 453]), '')
        );
    }

    public function testDeduplicatesRepeatedSlugs(): void
    {
        $options = TeaserFilterTerms::options_from_buckets(
            $this->buckets(['public-works' => 453]) + $this->buckets(['public-works' => 12]),
            'category'
        );

        $this->assertCount(1, $options);
    }
}
