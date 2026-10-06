<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator;

use InvalidArgumentException;

/**
 * A profile described by data: required and recommended property paths for a set of types.
 *
 * A rule is a property path such as `headline` or `offers.price`, `{"anyOf": [path, ...]}`, or
 * `{"path": path, "ifPresent": path}`. Paths follow arrays and `{"@id"}` references to nodes in the graph.
 */
final class RuleProfile implements Profile
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_LIMITED = 'limited';
    public const STATUS_DEPRECATED = 'deprecated';

    private string $id;
    private string $title;
    private string $source;
    private string $checked;
    private string $status;
    private string $statusNote;

    /** @var list<string> */
    private array $types;

    /** @var list<array{alternatives: list<string>, ifPresent: ?string}> */
    private array $required;

    /** @var list<array{alternatives: list<string>, ifPresent: ?string}> */
    private array $recommended;

    /**
     * @param array<mixed> $definition Decoded profile JSON.
     */
    public function __construct(array $definition)
    {
        $id = $definition['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException('A profile needs an "id".');
        }

        $status = $this->string($definition, 'status', self::STATUS_ACTIVE);
        if (!in_array($status, [self::STATUS_ACTIVE, self::STATUS_LIMITED, self::STATUS_DEPRECATED], true)) {
            throw new InvalidArgumentException("Profile \"{$id}\" has an unknown status \"{$status}\".");
        }

        $this->id = $id;
        $this->title = $this->string($definition, 'title', $id);
        $this->source = $this->string($definition, 'source', '');
        $this->checked = $this->string($definition, 'checked', '');
        $this->status = $status;
        $this->statusNote = $this->string($definition, 'statusNote', '');
        $this->types = $this->strings($definition['types'] ?? [], $id, 'types');
        $this->required = $this->rules($definition['required'] ?? [], $id, 'required');
        $this->recommended = $this->rules($definition['recommended'] ?? [], $id, 'recommended');
    }

    /**
     * Loads a profile from a JSON file.
     */
    public static function fromFile(string $path): self
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new InvalidArgumentException("Cannot read the profile file {$path}.");
        }

        $definition = json_decode($json, true);
        if (!is_array($definition)) {
            throw new InvalidArgumentException("The profile file {$path} is not a JSON object.");
        }

        return new self($definition);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function checked(): string
    {
        return $this->checked;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function statusNote(): string
    {
        return $this->statusNote;
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return $this->types;
    }

    public function check(Node $node, Graph $graph): array
    {
        if (!$this->appliesTo($node, $graph->vocabulary())) {
            return [];
        }

        if ($this->status === self::STATUS_DEPRECATED) {
            $note = $this->statusNote !== '' ? ' ' . $this->statusNote : '';

            return [$this->issue(
                Issue::NOTICE,
                'deprecated_feature',
                "{$this->title} is deprecated.{$note}",
                $node->path(),
                $node,
                null
            )];
        }

        $issues = [];
        foreach ($this->required as $rule) {
            $this->checkRule($rule, 'required', Issue::ERROR, 'missing_required', $node, $graph, $issues);
        }
        foreach ($this->recommended as $rule) {
            $this->checkRule($rule, 'recommended', Issue::WARNING, 'missing_recommended', $node, $graph, $issues);
        }

        return $issues;
    }

    private function appliesTo(Node $node, Vocabulary $vocabulary): bool
    {
        foreach ($node->types() as $type) {
            foreach ($this->types as $profileType) {
                if ($vocabulary->isSubtypeOf($type, $profileType)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{alternatives: list<string>, ifPresent: ?string} $rule
     * @param list<Issue> $issues
     */
    private function checkRule(
        array $rule,
        string $level,
        string $severity,
        string $code,
        Node $node,
        Graph $graph,
        array &$issues
    ): void {
        if ($rule['ifPresent'] !== null && !$this->exists($node->data(), explode('.', $rule['ifPresent']), $graph)) {
            return;
        }

        $name = implode(' or ', $rule['alternatives']);
        $sites = [];
        foreach ($rule['alternatives'] as $alternative) {
            $missing = $this->missing($node->data(), $node->path(), explode('.', $alternative), $graph);
            if ($missing === []) {
                return;
            }
            $sites = $sites === [] ? $missing : $sites;
        }

        foreach (array_unique($sites) as $site) {
            $issues[] = $this->issue(
                $severity,
                $code,
                "{$this->title}: missing {$level} property \"{$name}\".",
                $site,
                $node,
                $name
            );
        }
    }

    /**
     * Paths of the objects that lack the property at the end of the path; empty when the path is satisfied.
     *
     * @param array<mixed> $data
     * @param list<string> $segments
     * @return list<string>
     */
    private function missing(array $data, string $path, array $segments, Graph $graph): array
    {
        $segment = (string) array_shift($segments);
        $items = $this->items($data[$segment] ?? null, Terms::child($path, $segment));
        if ($items === []) {
            return [$path];
        }
        if ($segments === []) {
            return [];
        }

        $missing = [];
        foreach ($items as [$item, $itemPath]) {
            $target = $this->descend($item, $itemPath, $graph);
            if ($target === null) {
                $missing[] = $itemPath;
                continue;
            }
            foreach ($this->missing($target[0], $target[1], $segments, $graph) as $site) {
                $missing[] = $site;
            }
        }

        return $missing;
    }

    /**
     * True when at least one value exists at the end of the path.
     *
     * @param array<mixed> $data
     * @param list<string> $segments
     */
    private function exists(array $data, array $segments, Graph $graph): bool
    {
        $segment = (string) array_shift($segments);
        $items = $this->items($data[$segment] ?? null, '');
        if ($segments === []) {
            return $items !== [];
        }

        foreach ($items as [$item, $itemPath]) {
            $target = $this->descend($item, $itemPath, $graph);
            if ($target !== null && $this->exists($target[0], $segments, $graph)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The object a value points to: the value itself, or the node a reference names.
     *
     * @param mixed $item
     * @return array{array<mixed>, string}|null
     */
    private function descend($item, string $path, Graph $graph): ?array
    {
        if (!is_array($item) || array_key_exists('@value', $item)) {
            return null;
        }
        if (Graph::isReference($item)) {
            $node = $graph->find((string) $item['@id']);

            return $node === null ? null : [$node->data(), $node->path()];
        }

        return [$item, $path];
    }

    /**
     * The non-empty values of a property, flattening lists, each with its path.
     *
     * @param mixed $value
     * @return list<array{mixed, string}>
     */
    private function items($value, string $path): array
    {
        if (is_array($value) && Terms::isList($value)) {
            $items = [];
            foreach ($value as $index => $item) {
                foreach ($this->items($item, Terms::child($path, $index)) as $flattened) {
                    $items[] = $flattened;
                }
            }

            return $items;
        }
        if ($value === null || $value === [] || (is_string($value) && trim($value) === '')) {
            return [];
        }
        if (is_array($value) && array_key_exists('@value', $value) && trim((string) $value['@value']) === '') {
            return [];
        }

        return [[$value, $path]];
    }

    private function issue(
        string $severity,
        string $code,
        string $message,
        string $path,
        Node $node,
        ?string $property
    ): Issue {
        return new Issue($severity, $code, $message, $path, $node->primaryType(), $property, $this->id);
    }

    /**
     * @param array<mixed> $definition
     */
    private function string(array $definition, string $key, string $default): string
    {
        $value = $definition[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function strings($value, string $id, string $key): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException("Profile \"{$id}\" needs \"{$key}\" to be a list.");
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new InvalidArgumentException("Profile \"{$id}\" has an invalid entry in \"{$key}\".");
            }
        }

        return array_values($value);
    }

    /**
     * @param mixed $rules
     * @return list<array{alternatives: list<string>, ifPresent: ?string}>
     */
    private function rules($rules, string $id, string $key): array
    {
        if (!is_array($rules)) {
            throw new InvalidArgumentException("Profile \"{$id}\" needs \"{$key}\" to be a list.");
        }

        $parsed = [];
        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $parsed[] = ['alternatives' => $this->strings([$rule], $id, $key), 'ifPresent' => null];
            } elseif (is_array($rule) && isset($rule['anyOf'])) {
                $alternatives = $this->strings($rule['anyOf'], $id, $key);
                if ($alternatives === []) {
                    throw new InvalidArgumentException("Profile \"{$id}\" has an empty anyOf in \"{$key}\".");
                }
                $parsed[] = ['alternatives' => $alternatives, 'ifPresent' => $this->optionalPath($rule, $id, $key)];
            } elseif (is_array($rule) && isset($rule['path'])) {
                $parsed[] = [
                    'alternatives' => $this->strings([$rule['path']], $id, $key),
                    'ifPresent' => $this->optionalPath($rule, $id, $key),
                ];
            } else {
                throw new InvalidArgumentException("Profile \"{$id}\" has an invalid rule in \"{$key}\".");
            }
        }

        return $parsed;
    }

    /**
     * @param array<mixed> $rule
     */
    private function optionalPath(array $rule, string $id, string $key): ?string
    {
        if (!isset($rule['ifPresent'])) {
            return null;
        }

        return $this->strings([$rule['ifPresent']], $id, $key)[0];
    }
}
