<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator;

/**
 * Read access to the generated schema.org vocabulary.
 *
 * @phpstan-type TypeData array{
 *     parents: list<string>,
 *     properties: list<string>,
 *     pending: bool,
 *     supersededBy: list<string>
 * }
 * @phpstan-type PropertyData array{
 *     domains: list<string>,
 *     ranges: list<string>,
 *     pending: bool,
 *     supersededBy: list<string>
 * }
 * @phpstan-type VocabularyData array{
 *     version: string,
 *     types: array<string, TypeData>,
 *     properties: array<string, PropertyData>,
 *     dataTypes: list<string>,
 *     enumerations: array<string, list<string>>
 * }
 */
final class Vocabulary
{
    private static ?self $default = null;

    /** @var VocabularyData */
    private array $data;

    /** @var array<string, true> */
    private array $dataTypes;

    /** @var array<string, list<string>> */
    private array $ancestorCache = [];

    /**
     * @param VocabularyData|null $data Array in the format of data/vocabulary.php; null loads the bundled data.
     */
    public function __construct(?array $data = null)
    {
        if ($data === null) {
            /** @var VocabularyData $data */
            $data = require dirname(__DIR__) . '/data/vocabulary.php';
        }

        $this->data = $data;
        $this->dataTypes = array_fill_keys($data['dataTypes'], true);
    }

    /**
     * Returns a shared instance of the bundled vocabulary.
     */
    public static function default(): self
    {
        return self::$default ??= new self();
    }

    public function version(): string
    {
        return $this->data['version'];
    }

    public function hasType(string $type): bool
    {
        return isset($this->data['types'][$type]);
    }

    public function hasProperty(string $property): bool
    {
        return isset($this->data['properties'][$property]);
    }

    /**
     * All ancestors of a type, nearest first, without the type itself.
     *
     * @return list<string>
     */
    public function ancestors(string $type): array
    {
        if (isset($this->ancestorCache[$type])) {
            return $this->ancestorCache[$type];
        }

        $found = [];
        $queue = $this->data['types'][$type]['parents'] ?? [];
        while ($queue !== []) {
            $next = array_shift($queue);
            if (isset($found[$next])) {
                continue;
            }
            $found[$next] = true;
            foreach ($this->data['types'][$next]['parents'] ?? [] as $parent) {
                $queue[] = $parent;
            }
        }

        return $this->ancestorCache[$type] = array_keys($found);
    }

    /**
     * True when the type is the ancestor or one of its descendants.
     */
    public function isSubtypeOf(string $type, string $ancestor): bool
    {
        return $type === $ancestor || in_array($ancestor, $this->ancestors($type), true);
    }

    /**
     * Properties that apply to a type, including inherited ones.
     *
     * @return list<string>
     */
    public function propertiesOf(string $type): array
    {
        $properties = $this->data['types'][$type]['properties'] ?? [];
        foreach ($this->ancestors($type) as $ancestor) {
            $properties = array_merge($properties, $this->data['types'][$ancestor]['properties'] ?? []);
        }
        $properties = array_values(array_unique($properties));
        sort($properties, SORT_STRING);

        return $properties;
    }

    /**
     * True when the property lists the type, or one of its ancestors, as a domain.
     */
    public function propertyAppliesTo(string $property, string $type): bool
    {
        foreach ($this->domainsOf($property) as $domain) {
            if ($this->isSubtypeOf($type, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function domainsOf(string $property): array
    {
        return $this->data['properties'][$property]['domains'] ?? [];
    }

    /**
     * @return list<string>
     */
    public function rangesOf(string $property): array
    {
        return $this->data['properties'][$property]['ranges'] ?? [];
    }

    public function isDataType(string $type): bool
    {
        return isset($this->dataTypes[$type]);
    }

    public function isEnumeration(string $type): bool
    {
        return isset($this->data['enumerations'][$type]);
    }

    /**
     * Members listed directly under an enumeration type.
     *
     * @return list<string>
     */
    public function enumerationMembers(string $type): array
    {
        return $this->data['enumerations'][$type] ?? [];
    }

    /**
     * True when the name is a member of the enumeration type or of one of its subtypes.
     */
    public function isEnumerationMember(string $type, string $member): bool
    {
        foreach ($this->data['enumerations'] as $enumeration => $members) {
            if (in_array($member, $members, true) && $this->isSubtypeOf($enumeration, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the enumeration type, or a subtype, has any members.
     */
    public function hasEnumerationMembers(string $type): bool
    {
        foreach ($this->data['enumerations'] as $enumeration => $members) {
            if ($members !== [] && $this->isSubtypeOf($enumeration, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Names of the terms that replace a superseded type or property.
     *
     * @return list<string>
     */
    public function supersededBy(string $term): array
    {
        return $this->data['types'][$term]['supersededBy'] ?? $this->data['properties'][$term]['supersededBy'] ?? [];
    }

    public function isPending(string $term): bool
    {
        return $this->data['types'][$term]['pending'] ?? $this->data['properties'][$term]['pending'] ?? false;
    }
}
