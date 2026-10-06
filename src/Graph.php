<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator;

/**
 * Every node found in a JSON-LD document, nested ones included, indexed by `@id`.
 */
final class Graph
{
    private Vocabulary $vocabulary;

    /** @var list<Node> */
    private array $nodes = [];

    /** @var list<Node> */
    private array $entities = [];

    /** @var array<string, Node> */
    private array $definitions = [];

    /** @var array<string, list<Node>> */
    private array $definitionNodes = [];

    /** @var array<string, list<string>> */
    private array $typesById = [];

    /**
     * @param array<mixed> $document A decoded node, list of nodes, or object with `@graph`.
     */
    public function __construct(array $document, Vocabulary $vocabulary)
    {
        $this->vocabulary = $vocabulary;

        if (Terms::isList($document)) {
            $this->walkItems($document, '');
        } elseif (isset($document['@graph']) && $this->hasOnlyKeywords($document)) {
            $this->walkValue($document['@graph'], Terms::child('', '@graph'));
        } else {
            /** @var array<string, mixed> $document */
            $this->walkNode($document, '');
        }

        $this->mergeDefinitions();
    }

    public function vocabulary(): Vocabulary
    {
        return $this->vocabulary;
    }

    /**
     * All nodes in document order, parents before children. References are not nodes.
     *
     * @return list<Node>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * Like `nodes()`, but every `@id` defined more than once appears once, as the merged node.
     *
     * @return list<Node>
     */
    public function entities(): array
    {
        return $this->entities;
    }

    /**
     * The node defined with this `@id`. Several definitions are merged into one node at the path of the first.
     */
    public function find(string $id): ?Node
    {
        return $this->definitions[$id] ?? null;
    }

    /**
     * Types from every definition of the `@id`.
     *
     * @return list<string>
     */
    public function typesOf(string $id): array
    {
        return $this->typesById[$id] ?? [];
    }

    /**
     * True when the value is an object holding only an `@id`.
     *
     * @param mixed $value
     */
    public static function isReference($value): bool
    {
        return is_array($value) && count($value) === 1 && isset($value['@id']) && is_string($value['@id']);
    }

    /**
     * @param array<mixed> $document
     */
    private function hasOnlyKeywords(array $document): bool
    {
        foreach (array_keys($document) as $key) {
            if (strpos((string) $key, '@') !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $items
     */
    private function walkItems(array $items, string $path): void
    {
        foreach ($items as $index => $item) {
            $this->walkValue($item, Terms::child($path, $index));
        }
    }

    /**
     * @param mixed $value
     */
    private function walkValue($value, string $path): void
    {
        if (!is_array($value)) {
            return;
        }
        if (Terms::isList($value)) {
            $this->walkItems($value, $path);
        } elseif (isset($value['@list']) || isset($value['@set'])) {
            $key = isset($value['@list']) ? '@list' : '@set';
            $this->walkValue($value[$key], Terms::child($path, $key));
        } elseif (!array_key_exists('@value', $value) && !self::isReference($value)) {
            /** @var array<string, mixed> $value */
            $this->walkNode($value, $path);
        }
    }

    /**
     * Builds the merged node for each `@id` and the list of nodes with duplicate definitions merged.
     */
    private function mergeDefinitions(): void
    {
        foreach ($this->definitionNodes as $id => $nodes) {
            $this->definitions[$id] = count($nodes) === 1 ? $nodes[0] : $this->merge($nodes);
        }

        $emitted = [];
        foreach ($this->nodes as $node) {
            $id = $node->id();
            $isDefinition = $id !== null && in_array($node, $this->definitionNodes[$id] ?? [], true);
            if (!$isDefinition) {
                $this->entities[] = $node;
            } elseif ($id !== null && !isset($emitted[$id])) {
                $emitted[$id] = true;
                $this->entities[] = $this->definitions[$id];
            }
        }
    }

    /**
     * Combines definitions of one `@id`: values of each property are joined and identical ones dropped.
     *
     * @param list<Node> $nodes At least two definitions.
     */
    private function merge(array $nodes): Node
    {
        $values = [];
        foreach ($nodes as $node) {
            foreach ($node->data() as $key => $value) {
                $values[$key][] = $value;
            }
        }

        $data = [];
        foreach ($values as $key => $found) {
            if (count($found) === 1) {
                $data[$key] = $found[0];
                continue;
            }

            $unique = [];
            foreach ($found as $value) {
                foreach (is_array($value) && Terms::isList($value) ? $value : [$value] as $item) {
                    $unique[(string) json_encode($item)] = $item;
                }
            }
            $unique = array_values($unique);
            $data[$key] = count($unique) === 1 ? $unique[0] : $unique;
        }

        return new Node($data, $nodes[0]->path());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function walkNode(array $data, string $path): void
    {
        $node = new Node($data, $path);
        $this->nodes[] = $node;

        $id = $node->id();
        if ($id !== null && count($data) > 1) {
            $this->definitionNodes[$id][] = $node;
            $types = array_merge($this->typesById[$id] ?? [], $node->types());
            $this->typesById[$id] = array_values(array_unique($types));
        }

        foreach ($data as $key => $value) {
            if (strpos((string) $key, '@') !== 0 || $key === '@graph') {
                $this->walkValue($value, Terms::child($path, $key));
            }
        }
    }
}
