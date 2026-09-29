<?php

namespace Sifrious\Molly\Knowledge;

/**
 * Predefined learning path templates over Molly's graph namespaces.
 *
 * Each template declares a seed concept, the namespace/version to walk,
 * and a chain of relationship+direction steps. The builder traverses
 * these edges deterministically and reports gaps when connectivity is missing.
 */
final class LearningPathTemplates
{
    /**
     * @return list<array{id: string, title: string, description: string, seed: string, namespace: string, chain: list<array{relation: string, direction: string, why: string}>}>
     */
    public static function all(): array
    {
        return [
            [
                'id' => 'architecture-entry',
                'title' => 'Laravel architecture entry',
                'description' => 'Walk from the service container through binding resolution to an installed symbol.',
                'seed' => 'Container',
                'namespace' => 'laravel',
                'chain' => [
                    ['relation' => 'documented_in', 'direction' => 'outgoing', 'why' => 'The container concept links to its documentation section.'],
                    ['relation' => 'uses', 'direction' => 'incoming', 'why' => 'Documentation sections are referenced by the concepts that use them.'],
                ],
            ],
            [
                'id' => 'request-route-through-laravel',
                'title' => 'Request routing through Laravel',
                'description' => 'Follow a route definition from the concept through configuration to the framework facade.',
                'seed' => 'Route',
                'namespace' => 'laravel',
                'chain' => [
                    ['relation' => 'configured_by', 'direction' => 'outgoing', 'why' => 'A route is configured in routes/web.php.'],
                    ['relation' => 'uses', 'direction' => 'outgoing', 'why' => 'The routes file uses the Route facade.'],
                ],
            ],
            [
                'id' => 'task-test-evidence',
                'title' => 'Task to test to evidence',
                'description' => 'Trace a project task through its acceptance test and run evidence.',
                'seed' => 'Workspace',
                'namespace' => 'project',
                'chain' => [
                    ['relation' => 'runs_in', 'direction' => 'incoming', 'why' => 'Tasks run inside the workspace.'],
                    ['relation' => 'verified_by', 'direction' => 'outgoing', 'why' => 'Each task links to its acceptance test.'],
                ],
            ],
            [
                'id' => 'package-dependency-usage',
                'title' => 'Package and dependency usage',
                'description' => 'Walk from an Eloquent concept through its documentation to the framework symbol it depends on.',
                'seed' => 'Eloquent',
                'namespace' => 'laravel',
                'chain' => [
                    ['relation' => 'documented_in', 'direction' => 'outgoing', 'why' => 'The concept links to the documentation that explains it.'],
                    ['relation' => 'uses', 'direction' => 'incoming', 'why' => 'Other concepts use this documentation section.'],
                ],
            ],
            [
                'id' => 'change-to-evidence',
                'title' => 'Change to evidence',
                'description' => 'Follow a project task through run attempts to the verification evidence produced.',
                'seed' => 'Workspace',
                'namespace' => 'project',
                'chain' => [
                    ['relation' => 'runs_in', 'direction' => 'incoming', 'why' => 'Tasks belong to the workspace.'],
                    ['relation' => 'produced', 'direction' => 'outgoing', 'why' => 'Tasks produce run attempts with verification evidence.'],
                ],
            ],
        ];
    }

    /** @return array{id: string, title: string, description: string, seed: string, namespace: string, chain: list<array{relation: string, direction: string, why: string}>}|null */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $template) {
            if ($template['id'] === $id) {
                return $template;
            }
        }

        return null;
    }
}
