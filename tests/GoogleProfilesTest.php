<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator\Tests;

use HumanMade\SchemaOrgValidator\Issue;
use HumanMade\SchemaOrgValidator\Profiles;
use HumanMade\SchemaOrgValidator\RuleProfile;
use HumanMade\SchemaOrgValidator\Validator;
use HumanMade\SchemaOrgValidator\Vocabulary;
use PHPUnit\Framework\TestCase;

final class GoogleProfilesTest extends TestCase
{
    private const DIRECTORY = __DIR__ . '/../profiles/google';

    private const FIXTURES = __DIR__ . '/fixtures/google';

    /**
     * @return array<string, array{string}>
     */
    public function slugProvider(): array
    {
        $cases = [];
        foreach (glob(self::DIRECTORY . '/*.json') ?: [] as $file) {
            $slug = basename($file, '.json');
            $cases[$slug] = [$slug];
        }

        return $cases;
    }

    public function testEveryFeatureHasAProfile(): void
    {
        $ids = array_map(static fn ($profile): string => $profile->id(), Profiles::google());

        $this->assertCount(23, $ids);
        $this->assertSame(
            array_map(static fn (array $case): string => 'google/' . $case[0], array_values($this->slugProvider())),
            $ids
        );
    }

    /**
     * @dataProvider slugProvider
     */
    public function testProfileMetadata(string $slug): void
    {
        $profile = RuleProfile::fromFile(self::DIRECTORY . "/{$slug}.json");

        $this->assertSame("google/{$slug}", $profile->id());
        $this->assertStringStartsWith('https://developers.google.com/search/', $profile->source());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $profile->checked());
        $this->assertContains($profile->status(), ['active', 'limited', 'deprecated']);
        $this->assertNotSame([], $profile->types());
        if ($profile->status() !== 'active') {
            $this->assertNotSame('', $profile->statusNote());
        }
    }

    /**
     * @dataProvider slugProvider
     */
    public function testPathsAreKnownSchemaOrgProperties(string $slug): void
    {
        $vocabulary = Vocabulary::default();
        $definition = $this->definition($slug);
        foreach ($definition['types'] as $type) {
            $this->assertTrue($vocabulary->hasType($type), "{$slug}: unknown type {$type}");
        }

        foreach ($this->paths($definition) as [$path, $types]) {
            $segments = explode('.', $path);
            foreach ($segments as $segment) {
                $this->assertTrue($vocabulary->hasProperty($segment), "{$slug}: {$path}: unknown property {$segment}");
            }

            $applies = false;
            foreach ($types as $type) {
                $applies = $applies || $vocabulary->propertyAppliesTo($segments[0], $type);
            }
            $this->assertTrue($applies, "{$slug}: {$path}: {$segments[0]} does not apply to " . implode('/', $types));

            for ($i = 1; $i < count($segments); $i++) {
                $this->assertTrue(
                    $this->followsFrom($vocabulary, $segments[$i - 1], $segments[$i]),
                    "{$slug}: {$path}: {$segments[$i]} does not fit the values of {$segments[$i - 1]}"
                );
            }
        }
    }

    /**
     * @dataProvider slugProvider
     */
    public function testMinimalFixturePasses(string $slug): void
    {
        $fixture = $this->fixture($slug);
        $profile = RuleProfile::fromFile(self::DIRECTORY . "/{$slug}.json");
        $report = (new Validator(null, $profile))->validate($fixture['valid']);

        $this->assertSame([], $report->errors(), $slug . ': ' . json_encode($report->toArray()['issues']));
        $own = array_filter($report->notices(), static fn (Issue $i): bool => $i->source() === $profile->id());
        $codes = array_values(array_map(static fn (Issue $i): string => $i->code(), $own));
        $this->assertSame($profile->status() === 'deprecated' ? ['deprecated_feature'] : [], $codes, $slug);
    }

    /**
     * @dataProvider slugProvider
     */
    public function testMissingRequiredPropertyIsReported(string $slug): void
    {
        $fixture = $this->fixture($slug);
        $definition = $this->definition($slug);
        if ($definition['required'] === []) {
            $this->assertArrayNotHasKey('missing', $fixture);

            return;
        }

        $this->assertArrayHasKey('missing', $fixture);
        $missing = $fixture['missing'] ?? ['property' => '', 'jsonld' => []];
        $property = $missing['property'];
        $report = (new Validator(null, RuleProfile::fromFile(self::DIRECTORY . "/{$slug}.json")))
            ->validate($missing['jsonld']);

        $matches = array_filter(
            $report->errors(),
            static fn (Issue $i): bool => $i->code() === 'missing_required'
                && $i->property() === $property
                && $i->source() === "google/{$slug}"
        );
        $this->assertNotSame([], $matches, $slug . ': ' . json_encode($report->toArray()['issues']));
        $this->assertFalse($report->isValid());
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $slug): array
    {
        $definition = json_decode((string) file_get_contents(self::DIRECTORY . "/{$slug}.json"), true);
        $this->assertIsArray($definition);

        /** @var array<string, mixed> $definition */
        return $definition;
    }

    /**
     * Every property path in a profile, with the types it starts from.
     *
     * @param array<string, mixed> $definition
     * @return list<array{string, list<string>}>
     */
    private function paths(array $definition): array
    {
        $paths = [];
        foreach (['required', 'recommended'] as $level) {
            foreach ($definition[$level] as $rule) {
                $types = is_array($rule) && isset($rule['types']) ? $rule['types'] : $definition['types'];
                $rulePaths = is_string($rule) ? [$rule] : (array) ($rule['anyOf'] ?? [$rule['path']]);
                if (is_array($rule) && isset($rule['ifPresent'])) {
                    $rulePaths[] = $rule['ifPresent'];
                }
                foreach ($rulePaths as $path) {
                    $paths[] = [$path, $types];
                }
            }
        }

        return $paths;
    }

    private function followsFrom(Vocabulary $vocabulary, string $parent, string $property): bool
    {
        foreach ($vocabulary->rangesOf($parent) as $range) {
            foreach ($vocabulary->domainsOf($property) as $domain) {
                if ($vocabulary->isSubtypeOf($domain, $range) || $vocabulary->isSubtypeOf($range, $domain)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{valid: array<mixed>, missing?: array{property: string, jsonld: array<mixed>}}
     */
    private function fixture(string $slug): array
    {
        $fixture = json_decode((string) file_get_contents(self::FIXTURES . "/{$slug}.json"), true);
        $this->assertIsArray($fixture);

        /** @var array{valid: array<mixed>, missing?: array{property: string, jsonld: array<mixed>}} $fixture */
        return $fixture;
    }
}
