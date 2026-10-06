<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator;

/**
 * An object in a JSON-LD graph, with its location and its schema.org types.
 */
final class Node
{
    /** @var array<string, mixed> */
    private array $data;

    private string $path;

    /** @var list<string> */
    private array $types;

    /**
     * @param array<string, mixed> $data
     * @param string $path JSON pointer to the node in the input.
     */
    public function __construct(array $data, string $path)
    {
        $this->data = $data;
        $this->path = $path;

        $types = [];
        $raw = $data['@type'] ?? [];
        foreach (is_array($raw) ? $raw : [$raw] as $type) {
            $name = is_string($type) ? Terms::localName($type) : null;
            if ($name !== null) {
                $types[] = $name;
            }
        }
        $this->types = array_values(array_unique($types));
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function id(): ?string
    {
        $id = $this->data['@id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * Local names of the node's schema.org types; types from other vocabularies are left out.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * The first schema.org type, or null for an untyped node.
     */
    public function primaryType(): ?string
    {
        return $this->types[0] ?? null;
    }

    /**
     * @return mixed
     */
    public function get(string $property)
    {
        return $this->data[$property] ?? null;
    }
}
