<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator;

/**
 * Helpers for names and JSON pointers used across the validator.
 */
final class Terms
{
    /**
     * Reduces a bare name, `schema:Name` or a schema.org IRI to its local name.
     *
     * Returns null for terms in other namespaces.
     */
    public static function localName(string $term): ?string
    {
        foreach (['schema:', 'https://schema.org/', 'http://schema.org/'] as $prefix) {
            if (strpos($term, $prefix) === 0) {
                $name = substr($term, strlen($prefix));

                return $name === '' || strpbrk($name, ':/#') !== false ? null : $name;
            }
        }

        return $term === '' || strpbrk($term, ':/#') !== false ? null : $term;
    }

    /**
     * True for arrays with sequential integer keys starting at zero (including the empty array).
     *
     * @param array<mixed> $value
     */
    public static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Appends an escaped segment to a JSON pointer.
     *
     * @param string|int $segment
     */
    public static function child(string $path, $segment): string
    {
        return $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $segment);
    }
}
