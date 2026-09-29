<?php

namespace Sifrious\Molly\Knowledge;

final readonly class LearningStepView
{
    /**
     * @param list<LearningCitation> $citations
     */
    public function __construct(
        public int $position,
        public int $total,
        public string $node,
        public string $nodeKind,
        public string $why,
        public array $citations,
        public ?string $nextRelationship = null,
        public ?string $nextNode = null,
        public ?string $exercise = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'position' => $this->position,
            'total' => $this->total,
            'node' => $this->node,
            'node_kind' => $this->nodeKind,
            'why' => $this->why,
            'citations' => array_map(fn (LearningCitation $c): array => $c->toArray(), $this->citations),
            'next_relationship' => $this->nextRelationship,
            'next_node' => $this->nextNode,
            'exercise' => $this->exercise,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $citations = [];
        foreach ($data['citations'] ?? [] as $c) {
            if (is_array($c)) {
                $citations[] = LearningCitation::fromArray($c);
            }
        }

        return new self(
            (int) ($data['position'] ?? 0),
            (int) ($data['total'] ?? 0),
            (string) ($data['node'] ?? ''),
            (string) ($data['node_kind'] ?? ''),
            (string) ($data['why'] ?? ''),
            $citations,
            isset($data['next_relationship']) && is_string($data['next_relationship']) ? $data['next_relationship'] : null,
            isset($data['next_node']) && is_string($data['next_node']) ? $data['next_node'] : null,
            isset($data['exercise']) && is_string($data['exercise']) ? $data['exercise'] : null,
        );
    }
}
