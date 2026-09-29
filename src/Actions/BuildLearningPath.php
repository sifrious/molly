<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\GraphDelta\Freshness;
use Sifrious\Molly\Knowledge\LearningPathBuilder;
use Sifrious\Molly\Knowledge\LearningPathTemplates;
use Sifrious\Molly\Knowledge\LearningPathView;

class BuildLearningPath
{
    public function __construct(private LearningPathBuilder $builder) {}

    /**
     * Build a single learning path from a template ID for a given namespace version.
     */
    public function handle(string $templateId, string $version, string $repository = '', ?string $revisionRef = null): LearningPathView
    {
        $template = LearningPathTemplates::find($templateId);

        if ($template === null) {
            return new LearningPathView(
                id: $templateId,
                title: 'Unknown template',
                description: '',
                repository: $repository,
                revisionRef: $revisionRef,
                freshness: Freshness::Unavailable,
                steps: [],
                error: 'Template "' . $templateId . '" not found.',
                templateId: $templateId,
            );
        }

        return $this->builder->build([
            ...$template,
            'version' => $version,
            'repository' => $repository,
            'revision_ref' => $revisionRef,
        ]);
    }

    /**
     * Build all available learning paths for a namespace version.
     *
     * @return list<LearningPathView>
     */
    public function all(string $version, string $repository = '', ?string $revisionRef = null): array
    {
        $paths = [];
        foreach (LearningPathTemplates::all() as $template) {
            $paths[] = $this->builder->build([
                ...$template,
                'version' => $version,
                'repository' => $repository,
                'revision_ref' => $revisionRef,
            ]);
        }

        return $paths;
    }

    /**
     * List available template IDs with their titles.
     *
     * @return list<array{id: string, title: string, description: string, namespace: string}>
     */
    public function templates(): array
    {
        return array_map(fn (array $t): array => [
            'id' => $t['id'],
            'title' => $t['title'],
            'description' => $t['description'],
            'namespace' => $t['namespace'],
        ], LearningPathTemplates::all());
    }
}
