<?php

namespace Sifrious\Molly\Projects;

/** Immutable Molly project identity persisted under `.molly/project.json`. */
final class MollyProject
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $path,
        public readonly string $source,
        public readonly string $createdAt,
    ) {
        if (! in_array($this->source, ['new', 'existing'], true)) {
            throw new \RuntimeException('PROJECT_RECORD_INVALID: source must be new or existing.');
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'name', 'path', 'source', 'created_at'] as $key) {
            if (! is_string($data[$key] ?? null) || $data[$key] === '') {
                throw new \RuntimeException('PROJECT_RECORD_INVALID: Molly project metadata is incomplete.');
            }
        }

        return new self($data['id'], $data['name'], $data['path'], $data['source'], $data['created_at']);
    }

    /** @return array{id: string, name: string, path: string, source: string, created_at: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'path' => $this->path,
            'source' => $this->source,
            'created_at' => $this->createdAt,
        ];
    }
}
