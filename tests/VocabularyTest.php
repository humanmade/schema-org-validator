<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator\Tests;

use HumanMade\SchemaOrgValidator\Vocabulary;
use PHPUnit\Framework\TestCase;

final class VocabularyTest extends TestCase
{
    private Vocabulary $vocabulary;

    protected function setUp(): void
    {
        $this->vocabulary = Vocabulary::default();
    }

    public function testBundledDataLoadsOnce(): void
    {
        $this->assertSame(Vocabulary::default(), Vocabulary::default());
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $this->vocabulary->version());
    }

    public function testHasTypeAndProperty(): void
    {
        $this->assertTrue($this->vocabulary->hasType('Article'));
        $this->assertFalse($this->vocabulary->hasType('article'));
        $this->assertTrue($this->vocabulary->hasProperty('headline'));
        $this->assertFalse($this->vocabulary->hasProperty('Headline'));
    }

    public function testAncestors(): void
    {
        $this->assertSame(['CreativeWork', 'Thing'], $this->vocabulary->ancestors('Article'));
        $this->assertSame([], $this->vocabulary->ancestors('Thing'));
        $this->assertSame([], $this->vocabulary->ancestors('Nope'));
    }

    public function testMultipleInheritance(): void
    {
        $audiobook = $this->vocabulary->ancestors('Audiobook');
        foreach (['AudioObject', 'Book', 'MediaObject', 'CreativeWork', 'Thing'] as $expected) {
            $this->assertContains($expected, $audiobook);
        }
        $this->assertTrue($this->vocabulary->isSubtypeOf('Audiobook', 'Book'));
        $this->assertTrue($this->vocabulary->isSubtypeOf('Audiobook', 'MediaObject'));

        $campground = $this->vocabulary->ancestors('Campground');
        $this->assertContains('CivicStructure', $campground);
        $this->assertContains('LodgingBusiness', $campground);
        $this->assertContains('LocalBusiness', $campground);
        $this->assertContains('Place', $campground);
        $this->assertContains('Organization', $campground);
    }

    public function testIsSubtypeOfIsReflexive(): void
    {
        $this->assertTrue($this->vocabulary->isSubtypeOf('Article', 'Article'));
        $this->assertTrue($this->vocabulary->isSubtypeOf('NewsArticle', 'Article'));
        $this->assertFalse($this->vocabulary->isSubtypeOf('Article', 'NewsArticle'));
    }

    public function testPropertiesAreInherited(): void
    {
        $properties = $this->vocabulary->propertiesOf('NewsArticle');
        $this->assertContains('dateline', $properties);
        $this->assertContains('headline', $properties);
        $this->assertContains('name', $properties);
        $this->assertNotContains('birthDate', $properties);
        $this->assertSame($properties, array_values(array_unique($properties)));
    }

    public function testPropertyAppliesTo(): void
    {
        $this->assertTrue($this->vocabulary->propertyAppliesTo('headline', 'NewsArticle'));
        $this->assertTrue($this->vocabulary->propertyAppliesTo('name', 'Person'));
        $this->assertFalse($this->vocabulary->propertyAppliesTo('headline', 'Person'));
        $this->assertFalse($this->vocabulary->propertyAppliesTo('nope', 'Person'));
    }

    public function testRanges(): void
    {
        $this->assertSame(['Text'], $this->vocabulary->rangesOf('headline'));
        $this->assertSame(['Organization', 'Person'], $this->vocabulary->rangesOf('author'));
        $this->assertSame([], $this->vocabulary->rangesOf('nope'));
    }

    public function testDataTypes(): void
    {
        $types = ['Text', 'URL', 'CssSelectorType', 'XPathType', 'Number', 'Integer', 'Float', 'Date', 'Boolean'];
        foreach ($types as $type) {
            $this->assertTrue($this->vocabulary->isDataType($type), $type);
        }
        $this->assertFalse($this->vocabulary->isDataType('Person'));
    }

    public function testEnumerations(): void
    {
        $this->assertTrue($this->vocabulary->isEnumeration('ItemAvailability'));
        $this->assertFalse($this->vocabulary->isEnumeration('Person'));
        $this->assertContains('InStock', $this->vocabulary->enumerationMembers('ItemAvailability'));
        $this->assertTrue($this->vocabulary->isEnumerationMember('ItemAvailability', 'InStock'));
        $this->assertTrue($this->vocabulary->isEnumerationMember('Enumeration', 'InStock'));
        $this->assertFalse($this->vocabulary->isEnumerationMember('ItemAvailability', 'Monday'));
        $this->assertTrue($this->vocabulary->hasEnumerationMembers('ItemAvailability'));
        $this->assertFalse($this->vocabulary->hasEnumerationMembers('Person'));
    }

    public function testSupersededAndPending(): void
    {
        $this->assertSame(['SoftwareSourceCode'], $this->vocabulary->supersededBy('Code'));
        $this->assertSame(['interactionStatistic'], $this->vocabulary->supersededBy('interactionCount'));
        $this->assertSame([], $this->vocabulary->supersededBy('Article'));
        $this->assertTrue($this->vocabulary->isPending('CssSelectorType'));
        $this->assertFalse($this->vocabulary->isPending('Article'));
    }

    public function testInjectedData(): void
    {
        $vocabulary = new Vocabulary([
            'version' => '0.1',
            'types' => [
                'Thing' => ['parents' => [], 'properties' => ['name'], 'pending' => false, 'supersededBy' => []],
                'Dog' => ['parents' => ['Thing'], 'properties' => [], 'pending' => false, 'supersededBy' => []],
            ],
            'properties' => [
                'name' => ['domains' => ['Thing'], 'ranges' => ['Text'], 'pending' => false, 'supersededBy' => []],
            ],
            'dataTypes' => ['Text'],
            'enumerations' => [],
        ]);

        $this->assertSame('0.1', $vocabulary->version());
        $this->assertTrue($vocabulary->hasType('Dog'));
        $this->assertFalse($vocabulary->hasType('Article'));
        $this->assertSame(['name'], $vocabulary->propertiesOf('Dog'));
    }
}
