<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator\Tests;

use HumanMade\SchemaOrgValidator\Issue;
use HumanMade\SchemaOrgValidator\Profiles;
use HumanMade\SchemaOrgValidator\RuleProfile;
use HumanMade\SchemaOrgValidator\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RuleProfileTest extends TestCase
{
    /**
     * @param array<mixed> $profile
     * @param array<mixed> $jsonld
     * @return list<Issue>
     */
    private function validateWith(array $profile, array $jsonld): array
    {
        $profile += ['id' => 'test/profile', 'title' => 'Test', 'types' => ['Article']];

        return (new Validator(null, new RuleProfile($profile)))->validate($jsonld)->issues();
    }

    /**
     * @param list<Issue> $issues
     * @return list<string>
     */
    private function summary(array $issues): array
    {
        return array_map(
            static fn (Issue $i): string => $i->code() . ' ' . $i->path() . ' ' . $i->property(),
            $issues
        );
    }

    public function testMissingRequiredIsAnError(): void
    {
        $issues = $this->validateWith(['required' => ['headline']], ['@type' => 'Article']);

        $this->assertCount(1, $issues);
        $this->assertSame('missing_required', $issues[0]->code());
        $this->assertSame(Issue::ERROR, $issues[0]->severity());
        $this->assertSame('test/profile', $issues[0]->source());
        $this->assertSame('', $issues[0]->path());
        $this->assertSame('headline', $issues[0]->property());
        $this->assertSame('Article', $issues[0]->nodeType());
    }

    public function testMissingRecommendedIsAWarning(): void
    {
        $issues = $this->validateWith(['recommended' => ['image']], ['@type' => 'Article']);

        $this->assertSame('missing_recommended', $issues[0]->code());
        $this->assertSame(Issue::WARNING, $issues[0]->severity());
        $this->assertSame('test/profile', $issues[0]->source());
    }

    public function testPresentPropertiesPass(): void
    {
        $issues = $this->validateWith(['required' => ['headline']], ['@type' => 'Article', 'headline' => 'Hello']);

        $this->assertSame([], $issues);
    }

    public function testEmptyValuesCountAsMissing(): void
    {
        foreach ([null, '', '  ', []] as $empty) {
            $issues = $this->validateWith(['required' => ['headline']], ['@type' => 'Article', 'headline' => $empty]);
            $this->assertSame(['missing_required'], array_map(static fn ($i) => $i->code(), $issues));
        }
        $this->assertSame([], $this->validateWith(['required' => ['isAccessibleForFree']], [
            '@type' => 'Article',
            'isAccessibleForFree' => false,
        ]));
    }

    public function testAnyOf(): void
    {
        $profile = ['required' => [['anyOf' => ['image', 'thumbnailUrl']]]];

        $url = 'https://example.com/a.jpg';
        $this->assertSame([], $this->validateWith($profile, ['@type' => 'Article', 'image' => $url]));
        $this->assertSame([], $this->validateWith($profile, ['@type' => 'Article', 'thumbnailUrl' => $url]));
        $issues = $this->validateWith($profile, ['@type' => 'Article']);
        $this->assertCount(1, $issues);
        $this->assertSame('image or thumbnailUrl', $issues[0]->property());
    }

    public function testNestedPathThroughAnArray(): void
    {
        $profile = ['required' => ['author.name']];
        $article = [
            '@type' => 'Article',
            'author' => [
                ['@type' => 'Person', 'name' => 'Jane'],
                ['@type' => 'Person', 'url' => 'https://example.com/john'],
            ],
        ];

        $issues = $this->validateWith($profile, $article);

        $this->assertSame(['missing_required /author/1 author.name'], $this->summary($issues));
        $article['author'][1]['name'] = 'John';
        $this->assertSame([], $this->validateWith($profile, $article));
    }

    public function testNestedPathThroughAReference(): void
    {
        $profile = ['required' => ['author.name']];
        $graph = [
            '@graph' => [
                ['@type' => 'Article', 'author' => ['@id' => '#jane']],
                ['@type' => 'Person', '@id' => '#jane', 'url' => 'https://example.com/jane'],
            ],
        ];

        $issues = $this->validateWith($profile, $graph);
        $this->assertSame(['missing_required /@graph/1 author.name'], $this->summary($issues));

        $graph['@graph'][1]['name'] = 'Jane';
        $this->assertSame([], $this->validateWith($profile, $graph));
    }

    public function testDefinitionsOfOneIdAreMergedBeforeProfileRules(): void
    {
        $profile = ['types' => ['Organization'], 'required' => ['name', 'url']];
        $graph = [
            '@graph' => [
                ['@type' => 'Organization', '@id' => '#org', 'name' => 'Acme'],
                ['@type' => 'Article', 'publisher' => ['@id' => '#org']],
                ['@type' => 'Organization', '@id' => '#org', 'url' => 'https://example.com/'],
            ],
        ];

        $this->assertSame([], $this->validateWith($profile, $graph));

        unset($graph['@graph'][2]['url']);
        $this->assertSame(
            ['missing_required /@graph/0 url'],
            $this->summary($this->validateWith($profile, $graph))
        );
    }

    public function testMergedDefinitionsAreVisibleToPathsThroughReferences(): void
    {
        $profile = ['required' => ['author.name']];
        $graph = [
            '@graph' => [
                ['@type' => 'Article', 'author' => ['@id' => '#jane']],
                ['@type' => 'Person', '@id' => '#jane', 'url' => 'https://example.com/jane'],
                ['@type' => 'Person', '@id' => '#jane', 'name' => 'Jane'],
            ],
        ];

        $this->assertSame([], $this->validateWith($profile, $graph));
    }

    public function testVocabularyIssuesStayOnTheirOwnDefinition(): void
    {
        $issues = $this->validateWith([], [
            '@graph' => [
                ['@type' => 'Organization', '@id' => '#org', 'name' => 'Acme'],
                ['@type' => 'Organization', '@id' => '#org', 'notAProperty' => 'x'],
            ],
        ]);

        $this->assertSame(['unknown_property /@graph/1/notAProperty notAProperty'], $this->summary($issues));
    }

    public function testNestedPathThroughAnUndefinedReferenceIsMissing(): void
    {
        $issues = $this->validateWith(['required' => ['author.name']], [
            '@type' => 'Article',
            'author' => ['@id' => '#nobody'],
        ]);

        $this->assertSame(
            ['unresolved_reference /author author', 'missing_required /author author.name'],
            $this->summary($issues)
        );
    }

    public function testNestedPathWithoutTheParentIsMissing(): void
    {
        $issues = $this->validateWith(['required' => ['author.name']], ['@type' => 'Article']);

        $this->assertSame(['missing_required  author.name'], $this->summary($issues));
    }

    public function testIfPresent(): void
    {
        $profile = ['recommended' => [['path' => 'offers.price', 'ifPresent' => 'offers']], 'types' => ['Product']];

        $this->assertSame([], $this->validateWith($profile, ['@type' => 'Product']));
        $priced = ['@type' => 'Product', 'offers' => ['@type' => 'Offer', 'price' => 5]];
        $this->assertSame([], $this->validateWith($profile, $priced));
        $issues = $this->validateWith($profile, ['@type' => 'Product', 'offers' => ['@type' => 'Offer']]);
        $this->assertSame(['missing_recommended /offers offers.price'], $this->summary($issues));
    }

    public function testIfPresentCanBeNested(): void
    {
        $profile = [
            'required' => [['path' => 'offers.price', 'ifPresent' => 'offers.seller']],
            'types' => ['Product'],
        ];
        $withoutSeller = ['@type' => 'Product', 'offers' => ['@type' => 'Offer']];
        $withSeller = ['@type' => 'Product', 'offers' => ['@type' => 'Offer', 'seller' => ['@type' => 'Person']]];

        $this->assertSame([], $this->validateWith($profile, $withoutSeller));
        $this->assertCount(1, $this->validateWith($profile, $withSeller));
    }

    public function testProfileAppliesToSubtypesOnly(): void
    {
        $profile = ['required' => ['headline']];

        $this->assertCount(1, $this->validateWith($profile, ['@type' => 'NewsArticle']));
        $this->assertCount(1, $this->validateWith($profile, ['@type' => ['Thing', 'BlogPosting']]));
        $this->assertSame([], $this->validateWith($profile, ['@type' => 'Product']));
        $this->assertSame([], $this->validateWith($profile, ['name' => 'untyped']));
    }

    public function testAppliesToNestedNodes(): void
    {
        $issues = $this->validateWith(['required' => ['headline']], [
            '@type' => 'WebPage',
            'mainEntity' => ['@type' => 'Article'],
        ]);

        $this->assertSame(['missing_required /mainEntity headline'], $this->summary($issues));
    }

    public function testDeprecatedProfileAddsOneNoticePerMatchingNode(): void
    {
        $profile = [
            'status' => 'deprecated',
            'statusNote' => 'Google stopped showing this in 2024.',
            'required' => ['headline'],
        ];
        $issues = $this->validateWith($profile, [
            '@graph' => [['@type' => 'Article'], ['@type' => 'NewsArticle'], ['@type' => 'Product']],
        ]);

        $this->assertSame(
            ['deprecated_feature /@graph/0 ', 'deprecated_feature /@graph/1 '],
            $this->summary($issues)
        );
        $this->assertSame(Issue::NOTICE, $issues[0]->severity());
        $this->assertStringContainsString('Google stopped showing this in 2024.', $issues[0]->message());
        $this->assertSame('test/profile', $issues[0]->source());
    }

    public function testLimitedProfileChecksNormally(): void
    {
        $issues = $this->validateWith(['status' => 'limited', 'required' => ['headline']], ['@type' => 'Article']);

        $this->assertSame(['missing_required  headline'], $this->summary($issues));
    }

    public function testFromFile(): void
    {
        $profile = RuleProfile::fromFile(__DIR__ . '/fixtures/profiles/product.json');

        $this->assertSame('test/product', $profile->id());
        $this->assertSame('limited', $profile->status());
        $this->assertSame(['Product'], $profile->types());
        $this->assertSame('Only some regions.', $profile->statusNote());

        $validator = new Validator(null, $profile);
        $report = $validator->validate([
            '@type' => 'Product',
            'offers' => ['@type' => 'Offer', 'availability' => 'InStock'],
        ]);
        $this->assertSame(
            ['missing_required', 'missing_recommended', 'missing_recommended'],
            array_map(static fn (Issue $i): string => $i->code(), $report->issues())
        );
        $this->assertSame(['name', 'offers.price', 'brand'], array_map(
            static fn (Issue $i): ?string => $i->property(),
            $report->issues()
        ));
        $this->assertFalse($report->isValid());
    }

    public function testBadProfilesAreRejected(): void
    {
        foreach ([['title' => 'No id'], ['id' => 'x', 'status' => 'odd'], ['id' => 'x', 'required' => [5]]] as $bad) {
            try {
                new RuleProfile($bad);
                $this->fail('Expected an exception for ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        RuleProfile::fromFile(__DIR__ . '/fixtures/profiles/missing.json');
    }

    public function testBundledGoogleProfiles(): void
    {
        $profiles = Profiles::google();

        $this->assertNotSame([], $profiles);
        $this->assertContains('google/article', array_map(static fn ($p) => $p->id(), $profiles));
    }

    public function testGoogleArticleProfileOnAGraph(): void
    {
        $validator = new Validator(null, ...Profiles::google('google/article'));

        $valid = $validator->validate((string) file_get_contents(__DIR__ . '/fixtures/valid-article.json'));
        $this->assertSame([], $valid->issues());

        $report = $validator->validate([
            '@type' => 'BlogPosting',
            'headline' => 'Hello',
            'author' => ['@type' => 'Person', 'name' => 'Jane'],
        ]);
        $this->assertTrue($report->isValid());
        $this->assertSame(
            ['author.url or author.sameAs', 'dateModified', 'datePublished', 'image'],
            array_map(static fn (Issue $i): ?string => $i->property(), $report->warnings())
        );
        $this->assertSame('google/article', $report->warnings()[0]->source());
    }

    public function testRulesCanBeLimitedToTypes(): void
    {
        $profile = [
            'types' => ['Review', 'AggregateRating'],
            'required' => [
                ['path' => 'author', 'types' => ['Review']],
                ['anyOf' => ['ratingCount', 'reviewCount'], 'types' => ['AggregateRating']],
            ],
        ];

        $this->assertSame(['missing_required  author'], $this->summary($this->validateWith($profile, [
            '@type' => 'Review',
            'reviewRating' => ['@type' => 'Rating', 'ratingValue' => 5],
        ])));
        $this->assertSame(
            ['missing_required  ratingCount or reviewCount'],
            $this->summary($this->validateWith($profile, ['@type' => 'AggregateRating', 'ratingValue' => 4]))
        );
        $this->assertSame([], $this->validateWith($profile, [
            '@type' => 'AggregateRating',
            'reviewCount' => 3,
        ]));
    }

    public function testNotesAreKeptButNotChecked(): void
    {
        $profile = new RuleProfile([
            'id' => 'test/notes',
            'types' => ['Article'],
            'notes' => ['Not checked: the headline must be short.'],
        ]);

        $this->assertSame(['Not checked: the headline must be short.'], $profile->notes());
        $this->assertSame([], (new Validator(null, $profile))->validate(['@type' => 'Article'])->issues());
    }

    public function testGoogleProfilesCanBeSelectedById(): void
    {
        $this->assertSame(
            ['google/article', 'google/video'],
            array_map(static fn ($p) => $p->id(), Profiles::google('video', 'google/article'))
        );
        $this->assertSame([], Profiles::google('nope'));
    }
}
