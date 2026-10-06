<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator\Tests\Generator;

use PHPUnit\Framework\TestCase;

final class GeneratorTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $data;

    private string $base;

    private string $output;

    protected function setUp(): void
    {
        $this->base = (string) tempnam(sys_get_temp_dir(), 'vocab');
        $this->output = $this->base . '.php';
        $command = sprintf(
            '%s %s 9.9 --file=%s --output=%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../../bin/generate-vocabulary'),
            escapeshellarg(__DIR__ . '/../fixtures/generator/mini.jsonld'),
            escapeshellarg($this->output)
        );
        exec($command, $lines, $status);
        $this->assertSame(0, $status, implode("\n", $lines));
        $this->assertSame('9.9', end($lines));
        $this->data = require $this->output;
    }

    protected function tearDown(): void
    {
        @unlink($this->output);
        @unlink($this->base);
    }

    public function testVersionAndSortedKeys(): void
    {
        $this->assertSame('9.9', $this->data['version']);
        $names = array_keys($this->data['types']);
        $sorted = $names;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $names);
    }

    public function testMultipleParentsDropForeignOnes(): void
    {
        $this->assertSame(['Organization', 'Place'], $this->data['types']['LocalBusiness']['parents']);
        $this->assertSame(['Thing'], $this->data['types']['Organization']['parents']);
    }

    public function testPropertiesAreDeclaredOnTheirDomains(): void
    {
        $this->assertSame(['name', 'oldName', 'url'], $this->data['types']['Thing']['properties']);
        $this->assertSame(['url'], $this->data['types']['Place']['properties']);
        $this->assertSame(['Place', 'Thing'], $this->data['properties']['url']['domains']);
        $this->assertSame(['URL'], $this->data['properties']['url']['ranges']);
        $this->assertSame([], $this->data['properties']['orphan']['domains']);
        $this->assertArrayNotHasKey('isbn', $this->data['properties']);
    }

    public function testPendingAndSuperseded(): void
    {
        $this->assertTrue($this->data['types']['NewThing']['pending']);
        $this->assertFalse($this->data['types']['HealthThing']['pending']);
        $this->assertSame(['Place'], $this->data['types']['OldThing']['supersededBy']);
        $this->assertSame(['name'], $this->data['properties']['oldName']['supersededBy']);
    }

    public function testDataTypesIncludeSubtypes(): void
    {
        $this->assertSame(['Boolean', 'Text', 'URL'], $this->data['dataTypes']);
    }

    public function testEnumerationsListMembers(): void
    {
        $this->assertSame(['Color' => ['Red', 'Round'], 'Shape' => ['Round']], $this->data['enumerations']);
    }

    public function testOutputIsDeterministic(): void
    {
        $first = (string) file_get_contents($this->output);
        $command = sprintf(
            '%s %s 9.9 --file=%s --output=%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../../bin/generate-vocabulary'),
            escapeshellarg(__DIR__ . '/../fixtures/generator/mini.jsonld'),
            escapeshellarg($this->output)
        );
        exec($command);
        $this->assertSame($first, (string) file_get_contents($this->output));
    }
}
