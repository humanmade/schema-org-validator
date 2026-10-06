<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator;

/**
 * One problem found in a graph. Instances are immutable.
 *
 * @phpstan-type IssueData array{
 *     severity: string,
 *     code: string,
 *     message: string,
 *     path: string,
 *     nodeType: ?string,
 *     property: ?string,
 *     source: string
 * }
 */
final class Issue
{
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const NOTICE = 'notice';

    public const SOURCE_SCHEMA_ORG = 'schema.org';

    private string $severity;
    private string $code;
    private string $message;
    private string $path;
    private ?string $nodeType;
    private ?string $property;
    private string $source;

    /**
     * @param string $severity One of the severity constants.
     * @param string $path JSON pointer to the value the issue is about.
     * @param string $source `schema.org` or the id of the profile that raised the issue.
     */
    public function __construct(
        string $severity,
        string $code,
        string $message,
        string $path,
        ?string $nodeType = null,
        ?string $property = null,
        string $source = self::SOURCE_SCHEMA_ORG
    ) {
        $this->severity = $severity;
        $this->code = $code;
        $this->message = $message;
        $this->path = $path;
        $this->nodeType = $nodeType;
        $this->property = $property;
        $this->source = $source;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function nodeType(): ?string
    {
        return $this->nodeType;
    }

    public function property(): ?string
    {
        return $this->property;
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * @return IssueData
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'code' => $this->code,
            'message' => $this->message,
            'path' => $this->path,
            'nodeType' => $this->nodeType,
            'property' => $this->property,
            'source' => $this->source,
        ];
    }
}
