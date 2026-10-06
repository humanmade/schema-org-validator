<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator;

/**
 * Checks every node of a graph against the schema.org vocabulary.
 */
final class VocabularyChecker
{
    private Vocabulary $vocabulary;

    private Graph $graph;

    /** @var list<Issue> */
    private array $issues = [];

    public function __construct(Vocabulary $vocabulary)
    {
        $this->vocabulary = $vocabulary;
    }

    /**
     * @return list<Issue>
     */
    public function check(Graph $graph): array
    {
        $this->graph = $graph;
        $this->issues = [];

        foreach ($graph->nodes() as $node) {
            $this->checkTypes($node);
            $this->checkProperties($node);
        }

        return $this->issues;
    }

    private function checkTypes(Node $node): void
    {
        $raw = $node->get('@type');
        if ($raw === null) {
            return;
        }

        $path = Terms::child($node->path(), '@type');
        $items = is_array($raw) ? $raw : [$raw];
        foreach ($items as $index => $item) {
            $name = is_string($item) ? Terms::localName($item) : null;
            if ($name === null) {
                continue;
            }
            $itemPath = is_array($raw) ? Terms::child($path, $index) : $path;
            if (!$this->vocabulary->hasType($name)) {
                $this->add(Issue::ERROR, 'unknown_type', "Unknown type \"{$name}\".", $itemPath, $name);
                continue;
            }
            $this->checkLifecycle($name, 'Type', $itemPath, $name, null);
        }
    }

    private function checkProperties(Node $node): void
    {
        $knownTypes = array_values(array_filter($node->types(), [$this->vocabulary, 'hasType']));

        foreach ($node->data() as $key => $value) {
            $property = Terms::localName((string) $key);
            if (strpos((string) $key, '@') === 0 || $property === null) {
                continue;
            }

            $path = Terms::child($node->path(), $key);
            $nodeType = $node->primaryType();
            if (!$this->vocabulary->hasProperty($property)) {
                $message = "Unknown property \"{$property}\".";
                $this->add(Issue::ERROR, 'unknown_property', $message, $path, $nodeType, $property);
                continue;
            }

            $this->checkLifecycle($property, 'Property', $path, $nodeType, $property);

            $hasDomains = $this->vocabulary->domainsOf($property) !== [];
            if ($knownTypes !== [] && $hasDomains && !$this->appliesToAny($property, $knownTypes)) {
                $this->add(
                    Issue::ERROR,
                    'property_not_for_type',
                    "Property \"{$property}\" does not apply to " . $this->listOf($knownTypes, 'or', true) . '.',
                    $path,
                    $nodeType,
                    $property
                );
            }

            $this->checkValue($value, $path, $nodeType, $property);
        }
    }

    /**
     * @param mixed $value
     */
    private function checkValue($value, string $path, ?string $nodeType, string $property): void
    {
        if ($value === null) {
            return;
        }
        if (!is_array($value)) {
            $this->checkScalar($value, $path, $nodeType, $property);
        } elseif (Terms::isList($value)) {
            foreach ($value as $index => $item) {
                $this->checkValue($item, Terms::child($path, $index), $nodeType, $property);
            }
        } elseif (array_key_exists('@value', $value)) {
            if (is_scalar($value['@value'])) {
                $this->checkScalar($value['@value'], $path, $nodeType, $property);
            }
        } elseif (isset($value['@list']) || isset($value['@set'])) {
            $key = isset($value['@list']) ? '@list' : '@set';
            $this->checkValue($value[$key], Terms::child($path, $key), $nodeType, $property);
        } elseif (Graph::isReference($value)) {
            $this->checkReference((string) $value['@id'], $path, $nodeType, $property);
        } else {
            $this->checkObject(new Node($value, $path), $nodeType, $property);
        }
    }

    private function checkObject(Node $object, ?string $nodeType, string $property): void
    {
        $types = array_values(array_filter($object->types(), [$this->vocabulary, 'hasType']));
        if ($types === []) {
            return;
        }

        $this->checkObjectTypes($types, $object->path(), $nodeType, $property);
    }

    private function checkReference(string $id, string $path, ?string $nodeType, string $property): void
    {
        if ($this->graph->find($id) !== null) {
            $types = array_values(array_filter($this->graph->typesOf($id), [$this->vocabulary, 'hasType']));
            if ($types !== []) {
                $this->checkObjectTypes($types, $path, $nodeType, $property);
            }

            return;
        }

        $member = Terms::localName($id);
        foreach ($this->vocabulary->rangesOf($property) as $range) {
            if ($member !== null && $this->isEnumerationMember($range, $member)) {
                return;
            }
        }

        $this->add(
            Issue::NOTICE,
            'unresolved_reference',
            "Reference \"{$id}\" is not defined in this graph.",
            $path,
            $nodeType,
            $property
        );
    }

    /**
     * @param list<string> $types
     */
    private function checkObjectTypes(array $types, string $path, ?string $nodeType, string $property): void
    {
        $ranges = $this->vocabulary->rangesOf($property);
        if ($ranges === []) {
            return;
        }
        foreach ($types as $type) {
            foreach ($ranges as $range) {
                if ($this->vocabulary->isSubtypeOf($type, $range)) {
                    return;
                }
            }
        }

        $this->add(
            Issue::WARNING,
            'unexpected_value_type',
            "Property \"{$property}\" expects " . $this->listOf($ranges, 'or', true) . ', not '
                . $this->listOf($types, 'or', true) . '.',
            $path,
            $nodeType,
            $property
        );
    }

    /**
     * @param bool|float|int|string $value
     */
    private function checkScalar($value, string $path, ?string $nodeType, string $property): void
    {
        $ranges = $this->vocabulary->rangesOf($property);
        if ($ranges === []) {
            return;
        }

        $dataTypes = [];
        $enumerations = [];
        $objects = [];
        foreach ($ranges as $range) {
            if ($this->vocabulary->isDataType($range)) {
                $dataTypes[] = $range;
            } elseif ($this->isEnumerationRange($range)) {
                $enumerations[] = $range;
            } else {
                $objects[] = $range;
            }
        }

        if (is_string($value)) {
            $member = Terms::localName($value);
            foreach ($enumerations as $enumeration) {
                if ($member !== null && $this->isEnumerationMember($enumeration, $member)) {
                    return;
                }
            }
        }
        foreach ($dataTypes as $dataType) {
            if (ValueFormats::accepts($dataType, $value)) {
                return;
            }
        }

        $shown = $this->show($value);
        if ($objects !== [] && is_string($value)) {
            $this->add(
                Issue::NOTICE,
                'text_for_object',
                "Property \"{$property}\" has the text {$shown}; schema.org expects "
                    . $this->listOf($ranges, 'or', true) . '.',
                $path,
                $nodeType,
                $property
            );
        } elseif ($objects !== []) {
            $this->add(
                Issue::WARNING,
                'unexpected_value_type',
                "Property \"{$property}\" expects " . $this->listOf($ranges, 'or', true) . ", not the value {$shown}.",
                $path,
                $nodeType,
                $property
            );
        } elseif ($enumerations !== [] && $dataTypes === []) {
            $this->add(
                Issue::WARNING,
                'invalid_value',
                "Value {$shown} is not a member of " . $this->listOf($enumerations, 'or', false) . '.',
                $path,
                $nodeType,
                $property
            );
        } else {
            $this->add(
                Issue::WARNING,
                'invalid_value',
                "Value {$shown} is not a valid " . $this->listOf(array_merge($dataTypes, $enumerations), 'or', false)
                    . " for \"{$property}\".",
                $path,
                $nodeType,
                $property
            );
        }
    }

    private function isEnumerationRange(string $range): bool
    {
        return $this->vocabulary->isSubtypeOf($range, 'Enumeration')
            && $this->vocabulary->hasEnumerationMembers($range);
    }

    private function isEnumerationMember(string $range, string $member): bool
    {
        return $this->isEnumerationRange($range) && $this->vocabulary->isEnumerationMember($range, $member);
    }

    /**
     * @param list<string> $types
     */
    private function appliesToAny(string $property, array $types): bool
    {
        foreach ($types as $type) {
            if ($this->vocabulary->propertyAppliesTo($property, $type)) {
                return true;
            }
        }

        return false;
    }

    private function checkLifecycle(
        string $term,
        string $kind,
        string $path,
        ?string $nodeType,
        ?string $property
    ): void {
        $replacements = $this->vocabulary->supersededBy($term);
        if ($replacements !== []) {
            $this->add(
                Issue::WARNING,
                'superseded',
                "{$kind} \"{$term}\" is superseded by " . $this->listOf($replacements, 'or', true) . '.',
                $path,
                $nodeType,
                $property
            );
        }
        if ($this->vocabulary->isPending($term)) {
            $this->add(
                Issue::NOTICE,
                'pending',
                "{$kind} \"{$term}\" is pending in schema.org and may change.",
                $path,
                $nodeType,
                $property
            );
        }
    }

    /**
     * @param array<int, string> $names
     */
    private function listOf(array $names, string $conjunction, bool $quote): string
    {
        $names = array_map(static fn (string $name): string => $quote ? "\"{$name}\"" : $name, array_values($names));
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . " {$conjunction} {$last}";
    }

    /**
     * @param bool|float|int|string $value
     */
    private function show($value): string
    {
        $text = is_string($value) ? $value : (string) json_encode($value);
        if (preg_match('/^(.{40})./us', $text, $matches) === 1) {
            $text = $matches[1] . '...';
        }

        return '"' . $text . '"';
    }

    private function add(
        string $severity,
        string $code,
        string $message,
        string $path,
        ?string $nodeType = null,
        ?string $property = null
    ): void {
        $this->issues[] = new Issue($severity, $code, $message, $path, $nodeType, $property);
    }
}
