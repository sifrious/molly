<?php

namespace Sifrious\Molly\Knowledge;

use Sifrious\Molly\GraphDelta\Freshness;

final readonly class LearningPathView
{
    /**
     * @param list<LearningStepView> $steps
     * @param list<string> $prerequisites
     * @param list<string> $gaps
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $repository,
        public ?string $revisionRef,
        public Freshness $freshness,
        public array $steps,
        public ?string $error = null,
        public ?string $templateId = null,
        public array $prerequisites = [],
        public array $gaps = [],
        public bool $complete = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'repository' => $this->repository,
            'revision_ref' => $this->revisionRef,
            'freshness' => $this->freshness->value,
            'steps' => array_map(fn (LearningStepView $s): array => $s->toArray(), $this->steps),
            'error' => $this->error,
            'template_id' => $this->templateId,
            'prerequisites' => $this->prerequisites,
            'gaps' => $this->gaps,
            'complete' => $this->complete,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $steps = [];
        foreach ($data['steps'] ?? [] as $s) {
            if (is_array($s)) {
                $steps[] = LearningStepView::fromArray($s);
            }
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            repository: (string) ($data['repository'] ?? ''),
            revisionRef: isset($data['revision_ref']) && is_string($data['revision_ref']) ? $data['revision_ref'] : null,
            freshness: Freshness::tryFrom((string) ($data['freshness'] ?? '')) ?? Freshness::Unknown,
            steps: $steps,
            error: isset($data['error']) && is_string($data['error']) ? $data['error'] : null,
            templateId: isset($data['template_id']) && is_string($data['template_id']) ? $data['template_id'] : null,
            prerequisites: array_values(array_filter(
                $data['prerequisites'] ?? [],
                fn (mixed $v): bool => is_string($v),
            )),
            gaps: array_values(array_filter(
                $data['gaps'] ?? [],
                fn (mixed $v): bool => is_string($v),
            )),
            complete: (bool) ($data['complete'] ?? false),
        );
    }
}
