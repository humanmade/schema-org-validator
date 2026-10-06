<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator;

use JsonException;

/**
 * Validates schema.org JSON-LD against the vocabulary and any profiles.
 */
final class Validator
{
    private Vocabulary $vocabulary;

    /** @var list<Profile> */
    private array $profiles;

    public function __construct(?Vocabulary $vocabulary = null, Profile ...$profiles)
    {
        $this->vocabulary = $vocabulary ?? Vocabulary::default();
        $this->profiles = array_values($profiles);
    }

    /**
     * @param array<mixed>|string $jsonld A JSON string or decoded JSON-LD: a node, a list of nodes or `@graph`.
     */
    public function validate(array|string $jsonld): Report
    {
        if (is_string($jsonld)) {
            try {
                $jsonld = json_decode($jsonld, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                return $this->invalidJson('The input is not valid JSON: ' . $e->getMessage() . '.');
            }
            if (!is_array($jsonld)) {
                return $this->invalidJson('The JSON-LD must be an object or a list of objects.');
            }
        }

        if (Terms::isList($jsonld)) {
            foreach ($jsonld as $entry) {
                if (!is_array($entry)) {
                    return $this->invalidJson('The JSON-LD must be an object or a list of objects.');
                }
            }
        }

        $graph = new Graph($jsonld, $this->vocabulary);
        $issues = (new VocabularyChecker($this->vocabulary))->check($graph);

        foreach ($this->profiles as $profile) {
            foreach ($graph->nodes() as $node) {
                foreach ($profile->check($node, $graph) as $issue) {
                    $issues[] = $issue;
                }
            }
        }

        return new Report($issues);
    }

    private function invalidJson(string $message): Report
    {
        return new Report([new Issue(Issue::ERROR, 'invalid_json', $message, '')]);
    }
}
