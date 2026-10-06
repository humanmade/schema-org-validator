<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator\Tests;

use HumanMade\SchemaOrgValidator\Issue;
use HumanMade\SchemaOrgValidator\Report;
use HumanMade\SchemaOrgValidator\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    /**
     * @return list<string>
     */
    private function codes(Report $report): array
    {
        return array_map(static fn (Issue $issue): string => $issue->code(), $report->issues());
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name . '.json');
    }

    /**
     * @param array<mixed> $node
     * @return list<string>
     */
    private function codesFor(array $node): array
    {
        return $this->codes($this->validator->validate($node));
    }

    public function testValidArticleGraphHasNoIssues(): void
    {
        $report = $this->validator->validate($this->fixture('valid-article'));

        $this->assertSame([], $report->issues());
        $this->assertTrue($report->isValid());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function codeProvider(): array
    {
        return [
            'unknown_type' => ['unknown_type', Issue::ERROR],
            'unknown_property' => ['unknown_property', Issue::ERROR],
            'property_not_for_type' => ['property_not_for_type', Issue::ERROR],
            'unexpected_value_type' => ['unexpected_value_type', Issue::WARNING],
            'text_for_object' => ['text_for_object', Issue::NOTICE],
            'invalid_value' => ['invalid_value', Issue::WARNING],
            'superseded' => ['superseded', Issue::WARNING],
            'pending' => ['pending', Issue::NOTICE],
            'unresolved_reference' => ['unresolved_reference', Issue::NOTICE],
        ];
    }

    /**
     * @dataProvider codeProvider
     */
    public function testIssueCodeFiresOnItsFixtureOnly(string $code, string $severity): void
    {
        $bad = $this->validator->validate($this->fixture('codes/' . $code));
        $good = $this->validator->validate($this->fixture('codes/' . $code . '.valid'));

        $this->assertContains($code, $this->codes($bad));
        $this->assertNotContains($code, $this->codes($good));
        $matching = array_values(array_filter($bad->issues(), static fn (Issue $i): bool => $i->code() === $code));
        $this->assertSame($severity, $matching[0]->severity());
        $this->assertSame('schema.org', $matching[0]->source());
    }

    public function testErrorsMakeAReportInvalid(): void
    {
        $this->assertFalse($this->validator->validate($this->fixture('codes/unknown_type'))->isValid());
        $this->assertTrue($this->validator->validate($this->fixture('codes/text_for_object'))->isValid());
    }

    public function testInvalidJson(): void
    {
        $report = $this->validator->validate('{"@type": ');

        $this->assertSame(['invalid_json'], $this->codes($report));
        $this->assertFalse($report->isValid());
        $this->assertSame(['invalid_json'], $this->codes($this->validator->validate('"just text"')));
    }

    public function testIssueDetails(): void
    {
        $report = $this->validator->validate($this->fixture('nested-faq'));

        $this->assertCount(1, $report->issues());
        $issue = $report->issues()[0];
        $this->assertSame('unknown_property', $issue->code());
        $this->assertSame('/mainEntity/1/acceptedAnswer/txt', $issue->path());
        $this->assertSame('Answer', $issue->nodeType());
        $this->assertSame('txt', $issue->property());
        $this->assertSame('error', $report->toArray()['issues'][0]['severity']);
        $this->assertFalse($report->toArray()['valid']);
        $this->assertSame(['error' => 1, 'warning' => 0, 'notice' => 0], $report->toArray()['counts']);
    }

    public function testGraphAndListInputPaths(): void
    {
        $graph = $this->validator->validate($this->fixture('graph-input'));
        $list = $this->validator->validate($this->fixture('list-input'));

        $this->assertSame('/@graph/0/headlin', $graph->issues()[0]->path());
        $this->assertSame('/1/headlin', $list->issues()[0]->path());
        $this->assertCount(1, $list->issues());
    }

    public function testDecodedArrayInput(): void
    {
        $report = $this->validator->validate(['@type' => 'Article', 'headlin' => 'x']);

        $this->assertSame(['unknown_property'], $this->codes($report));
        $this->assertSame('/headlin', $report->issues()[0]->path());
    }

    public function testMultipleTypes(): void
    {
        $this->assertSame([], $this->codesFor(['@type' => ['Article', 'Product'], 'headline' => 'x', 'sku' => '1']));
        $this->assertSame(
            ['unknown_type'],
            $this->codesFor(['@type' => ['Article', 'Bogus'], 'headline' => 'x'])
        );
        $report = $this->validator->validate(['@type' => ['Article', 'Bogus']]);
        $this->assertSame('/@type/1', $report->issues()[0]->path());
        $this->assertSame(
            ['property_not_for_type'],
            $this->codesFor(['@type' => ['Article', 'Product'], 'birthDate' => '2000-01-01'])
        );
    }

    public function testPrefixedAndIriTypes(): void
    {
        foreach (['schema:Article', 'https://schema.org/Article', 'http://schema.org/Article'] as $type) {
            $this->assertSame([], $this->codesFor(['@type' => $type, 'headline' => 'x']), $type);
            $this->assertSame(
                ['property_not_for_type'],
                $this->codesFor(['@type' => $type, 'birthDate' => '2000-01-01']),
                $type
            );
        }
        $this->assertSame([], $this->codesFor(['@type' => 'schema:Article', 'schema:headline' => 'x']));
        $this->assertSame(['unknown_type'], $this->codesFor(['@type' => 'https://schema.org/Nope']));
    }

    public function testForeignTermsAreIgnored(): void
    {
        $this->assertSame([], $this->codesFor([
            '@context' => ['ex' => 'https://example.com/ns#'],
            '@type' => ['Article', 'ex:Special', 'https://example.com/ns#Other'],
            'headline' => 'x',
            'ex:rating' => 5,
            'https://example.com/ns#colour' => 'red',
        ]));
    }

    public function testUntypedNodesStillCheckPropertyNames(): void
    {
        $this->assertSame(['unknown_property'], $this->codesFor(['headlin' => 'x', 'name' => 'ok']));
    }

    public function testEnumerationValueForms(): void
    {
        $forms = ['InStock', 'schema:InStock', 'https://schema.org/InStock', 'http://schema.org/InStock'];
        foreach ($forms as $form) {
            $this->assertSame([], $this->codesFor(['@type' => 'Offer', 'availability' => $form]), $form);
        }
        $this->assertSame(
            [],
            $this->codesFor(['@type' => 'Offer', 'availability' => ['@id' => 'https://schema.org/InStock']])
        );
        $this->assertSame(['invalid_value'], $this->codesFor(['@type' => 'Offer', 'availability' => 'Plenty']));
        $this->assertSame(
            ['invalid_value'],
            $this->codesFor(['@type' => 'Offer', 'availability' => 'https://schema.org/Monday'])
        );
    }

    /**
     * @return array<string, array{string, string, mixed, bool}>
     */
    public function formatProvider(): array
    {
        return [
            'date ok' => ['Person', 'birthDate', '2020-02-29', true],
            'date year' => ['Person', 'birthDate', '1990', true],
            'date bad day' => ['Person', 'birthDate', '2021-02-29', false],
            'date wrong order' => ['Person', 'birthDate', '29/02/2020', false],
            'date with time' => ['Person', 'birthDate', '2020-01-01T10:00:00', false],
            'datetime ok' => ['Reservation', 'bookingTime', '2020-01-01T10:00:00+01:00', true],
            'datetime zulu' => ['Reservation', 'bookingTime', '2020-01-01T10:00Z', true],
            'datetime fraction' => ['Reservation', 'bookingTime', '2020-01-01T10:00:00.250Z', true],
            'datetime date only' => ['Reservation', 'bookingTime', '2020-01-01', false],
            'datetime bad hour' => ['Reservation', 'bookingTime', '2020-01-01T25:00:00', false],
            'datetime text' => ['Reservation', 'bookingTime', 'tomorrow', false],
            'time ok' => ['OpeningHoursSpecification', 'opens', '09:30:00', true],
            'time offset' => ['OpeningHoursSpecification', 'opens', '09:30Z', true],
            'time bad minute' => ['OpeningHoursSpecification', 'opens', '09:75', false],
            'time am' => ['OpeningHoursSpecification', 'opens', '9am', false],
            'number int' => ['UnitPriceSpecification', 'billingIncrement', 5, true],
            'number float' => ['UnitPriceSpecification', 'billingIncrement', 5.5, true],
            'number string' => ['UnitPriceSpecification', 'billingIncrement', '10.50', true],
            'number thousands' => ['UnitPriceSpecification', 'billingIncrement', '1,000', false],
            'number text' => ['UnitPriceSpecification', 'billingIncrement', 'ten', false],
            'number bool' => ['UnitPriceSpecification', 'billingIncrement', true, false],
            'integer ok' => ['Question', 'answerCount', 3, true],
            'integer string' => ['Question', 'answerCount', '3', true],
            'integer fraction' => ['Question', 'answerCount', 3.5, false],
            'integer string fraction' => ['Question', 'answerCount', '3.5', false],
            'boolean true' => ['Book', 'abridged', true, true],
            'boolean string' => ['Book', 'abridged', 'false', true],
            'boolean schema iri' => ['Book', 'abridged', 'https://schema.org/True', true],
            'boolean prefixed' => ['Book', 'abridged', 'schema:False', true],
            'boolean yes' => ['Book', 'abridged', 'yes', false],
            'boolean number' => ['Book', 'abridged', 1, false],
            'url ok' => ['MediaObject', 'contentUrl', 'https://example.com/a.mp4', true],
            'url mailto' => ['MediaObject', 'contentUrl', 'mailto:me@example.com', true],
            'url relative' => ['MediaObject', 'contentUrl', '/a.mp4', false],
            'url no host' => ['MediaObject', 'contentUrl', 'https://', false],
            'url spaces' => ['MediaObject', 'contentUrl', 'https://example.com/a b', false],
            'text accepts number' => ['Article', 'headline', 42, true],
            'text accepts bool' => ['Article', 'headline', false, true],
            'text or url accepts text' => ['Thing', 'additionalType', 'not a url', true],
            'date or datetime accepts date' => ['Article', 'datePublished', '2024-01-01', true],
            'date or datetime accepts datetime' => ['Article', 'datePublished', '2024-01-01T00:00:00Z', true],
            'number or text accepts text' => ['Offer', 'price', 'free', true],
        ];
    }

    /**
     * @dataProvider formatProvider
     * @param mixed $value
     */
    public function testDataTypeFormats(string $type, string $property, $value, bool $valid): void
    {
        $codes = $this->codesFor(['@type' => $type, $property => $value]);

        $this->assertSame($valid ? [] : ['invalid_value'], $codes);
    }

    public function testValuesInsideListsAndValueObjects(): void
    {
        $report = $this->validator->validate([
            '@type' => 'Person',
            'birthDate' => ['2000-01-01', 'soon'],
            'deathDate' => ['@value' => 'later', '@type' => 'Date'],
        ]);

        $this->assertSame(['invalid_value', 'invalid_value'], $this->codes($report));
        $this->assertSame('/birthDate/1', $report->issues()[0]->path());
        $this->assertSame('/deathDate', $report->issues()[1]->path());
    }

    public function testNonStringForObjectRangeIsAWarning(): void
    {
        $this->assertSame(['unexpected_value_type'], $this->codesFor(['@type' => 'Article', 'author' => 5]));
    }

    public function testObjectTypesMatchRangesThroughSubtypes(): void
    {
        $this->assertSame([], $this->codesFor([
            '@type' => 'Article',
            'publisher' => ['@type' => 'NewsMediaOrganization', 'name' => 'x'],
        ]));
        $this->assertSame(['unexpected_value_type'], $this->codesFor([
            '@type' => 'Article',
            'publisher' => ['@type' => 'Event', 'name' => 'x'],
        ]));
    }

    public function testReferencesToDefinedNodesAreTypeChecked(): void
    {
        $graph = [
            '@graph' => [
                ['@type' => 'Article', '@id' => '#a', 'author' => ['@id' => '#e'], 'publisher' => ['@id' => '#o']],
                ['@type' => 'Event', '@id' => '#e', 'name' => 'Conference'],
                ['@type' => 'Organization', '@id' => '#o', 'name' => 'Org'],
            ],
        ];
        $report = $this->validator->validate($graph);

        $this->assertSame(['unexpected_value_type'], $this->codes($report));
        $this->assertSame('/@graph/0/author', $report->issues()[0]->path());
    }

    public function testReferencesToUndefinedNodes(): void
    {
        $report = $this->validator->validate([
            '@graph' => [['@type' => 'Article', 'publisher' => ['@id' => '#missing']]],
        ]);

        $this->assertSame(['unresolved_reference'], $this->codes($report));
        $this->assertSame('/@graph/0/publisher', $report->issues()[0]->path());
    }

    public function testNestedDefinitionsResolveReferences(): void
    {
        $report = $this->validator->validate([
            '@graph' => [
                [
                    '@type' => 'Article',
                    'publisher' => ['@id' => '#o'],
                    'author' => ['@type' => 'Person', '@id' => '#p'],
                ],
                [
                    '@type' => 'WebPage',
                    'author' => ['@id' => '#p'],
                    'publisher' => ['@type' => 'Organization', '@id' => '#o'],
                ],
            ],
        ]);

        $this->assertSame([], $report->issues());
    }

    public function testSupersededPropertyNamesTheReplacement(): void
    {
        $report = $this->validator->validate(['@type' => 'Article', 'interactionCount' => 'UserLikes:5']);

        $this->assertContains('superseded', $this->codes($report));
        $messages = array_map(static fn (Issue $i): string => $i->message(), $report->issues());
        $this->assertStringContainsString('interactionStatistic', implode(' ', $messages));
    }

    public function testSupersededTypeNamesTheReplacement(): void
    {
        $issue = $this->validator->validate(['@type' => 'Code'])->issues()[0];

        $this->assertStringContainsString('SoftwareSourceCode', $issue->message());
        $this->assertSame('/@type', $issue->path());
        $this->assertSame('Code', $issue->nodeType());
    }
}
