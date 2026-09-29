<?php

namespace Sifrious\Molly\GraphDelta;

use InvalidArgumentException;

final readonly class GraphDeltaView
{
    public const VERSION = 'graph-delta-view-1';

    /**
     * @param  array<string, list<ChangeItemView>>  $sections
     */
    public function __construct(
        public string $version,
        public DeltaProvenance $provenance,
        public array $sections,
        public ?string $beforeRevision = null,
        public ?string $afterRevision = null,
    ) {
        if ($this->version !== self::VERSION) {
            throw new InvalidArgumentException(
                'CONTRACT_VERSION_INVALID: Expected '.self::VERSION.", got {$this->version}."
            );
        }

        $allowed = array_map(fn (Section $s) => $s->value, Section::cases());

        foreach (array_keys($this->sections) as $name) {
            if (! in_array($name, $allowed, true)) {
                throw new InvalidArgumentException(
                    "CONTRACT_SECTION_INVALID: Unknown section {$name}. Allowed: ".implode(', ', $allowed).'.'
                );
            }
        }

        foreach ($allowed as $name) {
            if (! array_key_exists($name, $this->sections)) {
                throw new InvalidArgumentException(
                    "CONTRACT_SECTION_MISSING: Section {$name} is required."
                );
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $sections = [];
        foreach ($this->sections as $name => $items) {
            $sections[$name] = array_map(fn (ChangeItemView $item) => $item->toArray(), $items);
        }

        return [
            'version' => $this->version,
            'provenance' => $this->provenance->toArray(),
            'sections' => $sections,
            'before_revision' => $this->beforeRevision,
            'after_revision' => $this->afterRevision,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! is_string($data['version'] ?? null)) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: version must be a string.');
        }

        if (! is_array($data['provenance'] ?? null)) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: provenance must be an object.');
        }

        if (! is_array($data['sections'] ?? null)) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: sections must be an object.');
        }

        $sections = [];
        foreach ($data['sections'] as $name => $items) {
            if (! is_array($items) || ($items !== [] && ! array_is_list($items))) {
                throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: sections.{$name} must be a list.");
            }
            $sections[$name] = array_map(fn (array $item) => ChangeItemView::fromArray($item), $items);
        }

        return new self(
            $data['version'],
            DeltaProvenance::fromArray($data['provenance']),
            $sections,
            is_string($data['before_revision'] ?? null) ? $data['before_revision'] : null,
            is_string($data['after_revision'] ?? null) ? $data['after_revision'] : null,
        );
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new InvalidArgumentException('CONTRACT_JSON_INVALID: Expected a JSON object.');
        }

        return self::fromArray($data);
    }

    /** Items from the changed_directly section. */
    public function changedDirectly(): array
    {
        return $this->sections[Section::ChangedDirectly->value];
    }

    /** Items from the affected_context section. */
    public function affectedContext(): array
    {
        return $this->sections[Section::AffectedContext->value];
    }

    /** Items from the tests_contracts section. */
    public function testsContracts(): array
    {
        return $this->sections[Section::TestsContracts->value];
    }

    /** Items from the unknown_impact section. */
    public function unknownImpact(): array
    {
        return $this->sections[Section::UnknownImpact->value];
    }

    /** Items from the visual_changes section. */
    public function visualChanges(): array
    {
        return $this->sections[Section::VisualChanges->value];
    }

    public function totalItems(): int
    {
        $count = 0;
        foreach ($this->sections as $items) {
            $count += count($items);
        }

        return $count;
    }

    public function hasUnknownImpact(): bool
    {
        return $this->unknownImpact() !== [];
    }
}
