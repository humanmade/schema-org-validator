<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator\Tests;

use HumanMade\SchemaOrgValidator\Graph;
use HumanMade\SchemaOrgValidator\Vocabulary;
use PHPUnit\Framework\TestCase;

final class GraphTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private function split(): array
    {
        return [
            '@graph' => [
                ['@type' => 'Organization', '@id' => '#org', 'name' => 'Acme', 'sameAs' => 'https://a.example/acme'],
                ['@type' => 'Article', 'publisher' => ['@id' => '#org']],
                [
                    '@type' => ['LocalBusiness', 'Organization'],
                    '@id' => '#org',
                    'url' => 'https://example.com/',
                    'sameAs' => ['https://a.example/acme', 'https://b.example/acme'],
                ],
            ],
        ];
    }

    public function testDefinitionsOfOneIdAreMerged(): void
    {
        $graph = new Graph($this->split(), Vocabulary::default());
        $node = $graph->find('#org');

        $this->assertNotNull($node);
        $this->assertSame(['Organization', 'LocalBusiness'], $node->types());
        $this->assertSame('/@graph/0', $node->path());
        $this->assertSame('Acme', $node->get('name'));
        $this->assertSame('https://example.com/', $node->get('url'));
        $this->assertSame(['https://a.example/acme', 'https://b.example/acme'], $node->get('sameAs'));
        $this->assertSame(['Organization', 'LocalBusiness'], $graph->typesOf('#org'));
    }

    public function testIdenticalValuesCollapseToOneScalar(): void
    {
        $graph = new Graph(['@graph' => [
            ['@id' => '#a', '@type' => 'Thing', 'name' => 'A'],
            ['@id' => '#a', '@type' => 'Thing', 'name' => 'A'],
        ]], Vocabulary::default());
        $node = $graph->find('#a');

        $this->assertNotNull($node);
        $this->assertSame('A', $node->get('name'));
        $this->assertSame('Thing', $node->get('@type'));
    }

    public function testNodesKeepEveryDefinitionAndEntitiesMergeThem(): void
    {
        $graph = new Graph($this->split(), Vocabulary::default());

        $this->assertSame(['/@graph/0', '/@graph/1', '/@graph/2'], array_map(
            static fn ($node): string => $node->path(),
            $graph->nodes()
        ));
        $this->assertSame(['/@graph/0', '/@graph/1'], array_map(
            static fn ($node): string => $node->path(),
            $graph->entities()
        ));
        $this->assertSame($graph->find('#org'), $graph->entities()[0]);
    }

    public function testNodesWithoutDuplicatesAreUnchanged(): void
    {
        $graph = new Graph(['@graph' => [
            ['@type' => 'Person', '@id' => '#a', 'name' => 'A'],
            ['@type' => 'Person', 'name' => 'B'],
        ]], Vocabulary::default());

        $this->assertSame($graph->nodes(), $graph->entities());
    }
}
