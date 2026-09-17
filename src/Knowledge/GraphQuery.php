<?php

namespace Sifrious\Molly\Knowledge;

use InvalidArgumentException;

final readonly class GraphQuery
{
    /** @param  list<string>  $relations */
    public function __construct(
        public string $namespace,
        public string $version,
        public string $concept,
        public int $depth = 2,
        public int $limit = 20,
        public array $relations = [],
    ) {
        if (trim($concept) === '') {
            throw new InvalidArgumentException('KNOWLEDGE_CONCEPT_REQUIRED: Enter a concept to query.');
        }

        if ($depth < 0 || $depth > 3) {
            throw new InvalidArgumentException('KNOWLEDGE_DEPTH_INVALID: Depth must be between 0 and 3.');
        }

        if ($limit < 1 || $limit > 40) {
            throw new InvalidArgumentException('KNOWLEDGE_LIMIT_INVALID: Limit must be between 1 and 40.');
        }

        if (count($relations) > 20) {
            throw new InvalidArgumentException('KNOWLEDGE_RELATIONS_INVALID: Choose no more than 20 relationships.');
        }

        foreach ($relations as $relation) {
            if (! preg_match('/\A[a-z][a-z0-9_]{0,49}\z/', $relation)) {
                throw new InvalidArgumentException('KNOWLEDGE_RELATION_INVALID: Relationship names use lowercase letters, numbers, and underscores.');
            }
        }
    }
}
