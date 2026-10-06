<?php

declare(strict_types=1);

namespace HumanMade\SchemaOrgValidator;

/**
 * A set of extra rules applied to the nodes of a graph, such as a Google rich result guide.
 */
interface Profile
{
    /**
     * Identifier used as the `source` of the issues this profile raises.
     */
    public function id(): string;

    /**
     * Checks one node. Called for every node in the graph, so the profile decides whether it applies.
     * Definitions that share an `@id` arrive as one merged node.
     *
     * @return list<Issue>
     */
    public function check(Node $node, Graph $graph): array;
}
