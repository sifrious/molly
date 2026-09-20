<?php

namespace Sifrious\Molly\Knowledge;

use Sifrious\Molly\Contracts\JsonDocument;

/**
 * Stable JIT context pack for agent adapters. Not raw graph storage.
 */
final readonly class ContextPack
{
    public const SCHEMA = 'molly.context_pack.v1';

    /**
     * @param  array{prompt_digest: string, files: list<string>, test_path: string, needles: list<string>, concepts: list<string>}  $query
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public string $status,
        public ?string $version,
        public array $query,
        public array $items,
        public string $reason,
    ) {
        if (! in_array($this->status, ['advisory', 'empty', 'unavailable'], true)) {
            throw new \InvalidArgumentException('CONTEXT_PACK_STATUS_INVALID: status must be advisory, empty, or unavailable.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $concepts = array_values(array_map(fn (array $item): string => (string) $item['concept'], $this->items));
        $neighborhoods = array_map(fn (array $item): array => [
            'concept' => $item['concept'],
            'matched' => $item['matched'],
            'truncated' => $item['truncated'],
            'selection_reason' => $item['selection_reason'],
            'nodes' => $item['nodes'],
            'edges' => $item['edges'],
            'sources' => $item['sources'],
        ], $this->items);

        return [
            'schema' => self::SCHEMA,
            'status' => $this->status,
            'version' => $this->version,
            'query' => $this->query,
            'items' => $this->items,
            'concepts' => $concepts,
            'neighborhoods' => $neighborhoods,
            'reason' => $this->reason,
        ];
    }

    public function toJson(): string
    {
        return JsonDocument::encode($this->toArray());
    }
}
