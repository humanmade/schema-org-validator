<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator;

/**
 * The issues found by a validation run.
 *
 * @phpstan-import-type IssueData from Issue
 */
final class Report
{
    /** @var list<Issue> */
    private array $issues;

    /**
     * @param list<Issue> $issues
     */
    public function __construct(array $issues = [])
    {
        $this->issues = $issues;
    }

    /**
     * @return list<Issue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<Issue>
     */
    public function errors(): array
    {
        return $this->bySeverity(Issue::ERROR);
    }

    /**
     * @return list<Issue>
     */
    public function warnings(): array
    {
        return $this->bySeverity(Issue::WARNING);
    }

    /**
     * @return list<Issue>
     */
    public function notices(): array
    {
        return $this->bySeverity(Issue::NOTICE);
    }

    /**
     * True when there are no errors. Warnings and notices do not make a graph invalid.
     */
    public function isValid(): bool
    {
        return $this->errors() === [];
    }

    /**
     * @return array{
     *     valid: bool,
     *     counts: array{error: int, warning: int, notice: int},
     *     issues: list<IssueData>
     * }
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'counts' => [
                Issue::ERROR => count($this->errors()),
                Issue::WARNING => count($this->warnings()),
                Issue::NOTICE => count($this->notices()),
            ],
            'issues' => array_map(static fn (Issue $issue): array => $issue->toArray(), $this->issues),
        ];
    }

    /**
     * @return list<Issue>
     */
    private function bySeverity(string $severity): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (Issue $issue): bool => $issue->severity() === $severity
        ));
    }
}
