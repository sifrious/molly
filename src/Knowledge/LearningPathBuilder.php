<?php

namespace Sifrious\Molly\Knowledge;

use Sifrious\Molly\GraphDelta\Freshness;

/**
 * Deterministic path builder that walks graph relationships to produce
 * ordered learning steps. Paths follow predefined templates that describe
 * a chain of relationship types to traverse from a seed concept.
 *
 * The builder never fabricates steps. When connectivity is missing, it
 * records a gap and stops the walk.
 */
final class LearningPathBuilder
{
    public function __construct(private Graph $graph) {}

    /**
     * Build a learning path from a template specification.
     *
     * @param array{id: string, title: string, description: string, seed: string, namespace: string, version: string, chain: list<array{relation: string, direction: string, why: string}>, repository: string, revision_ref: string|null} $template
     */
    public function build(array $template): LearningPathView
    {
        $namespace = $template['namespace'];
        $version = $template['version'];
        $seed = $template['seed'];
        $chain = $template['chain'];

        $relations = array_values(array_unique(array_map(
            fn (array $link): string => $link['relation'],
            $chain,
        )));

        $depth = max(1, count($chain));
        if ($depth > 3) {
            $depth = 3;
        }

        $result = $this->graph->query(new GraphQuery(
            namespace: $namespace,
            version: $version,
            concept: $seed,
            depth: $depth,
            limit: 40,
            relations: $relations,
        ));

        if ($result->nodes === []) {
            return new LearningPathView(
                id: $template['id'],
                title: $template['title'],
                description: $template['description'],
                repository: $template['repository'],
                revisionRef: $template['revision_ref'],
                freshness: Freshness::Unavailable,
                steps: [],
                error: 'Seed concept "' . $seed . '" not found in ' . $namespace . '/' . $version . '.',
                templateId: $template['id'],
            );
        }

        $nodesById = [];
        foreach ($result->nodes as $node) {
            $nodesById[$node['id']] = $node;
        }

        $seedNode = $this->bestSeedMatch($result->nodes, $seed);
        $totalSteps = count($chain) + 1;
        $steps = [];
        $gaps = [];
        $currentNodeId = $seedNode['id'];

        $steps[] = new LearningStepView(
            position: 1,
            total: $totalSteps,
            node: $seedNode['label'],
            nodeKind: $seedNode['type'],
            why: 'Starting point for this path.',
            citations: $this->citationsForNode($seedNode),
            nextRelationship: $chain !== [] ? $chain[0]['relation'] : null,
            nextNode: null,
        );

        foreach ($chain as $index => $link) {
            $next = $this->walkEdge(
                $result->edges,
                $nodesById,
                $currentNodeId,
                $link['relation'],
                $link['direction'],
            );

            if ($next === null) {
                $currentLabel = $nodesById[$currentNodeId]['label'] ?? $currentNodeId;
                $gaps[] = 'No ' . $link['relation'] . ' edge from "' . $currentLabel . '" in ' . $namespace . '/' . $version . '.';
                break;
            }

            $position = $index + 2;
            $nextChain = $chain[$index + 1] ?? null;

            $steps[] = new LearningStepView(
                position: $position,
                total: $totalSteps,
                node: $next['label'],
                nodeKind: $next['type'],
                why: $link['why'],
                citations: $this->citationsForNode($next),
                nextRelationship: $nextChain !== null ? $nextChain['relation'] : null,
                nextNode: null,
            );

            $currentNodeId = $next['id'];
        }

        $complete = count($steps) === $totalSteps && $gaps === [];

        return new LearningPathView(
            id: $template['id'],
            title: $template['title'],
            description: $template['description'],
            repository: $template['repository'],
            revisionRef: $template['revision_ref'],
            freshness: $gaps !== [] ? Freshness::PartiallyUpdated : Freshness::Current,
            steps: $steps,
            error: null,
            templateId: $template['id'],
            prerequisites: [],
            gaps: $gaps,
            complete: $complete,
        );
    }

    /**
     * Walk one named relationship from a node within the already-fetched result set.
     * Returns the first matching neighbor sorted by label for determinism.
     *
     * @param list<array<string, mixed>> $edges
     * @param array<string, array<string, mixed>> $nodesById
     * @return array<string, mixed>|null
     */
    private function walkEdge(array $edges, array $nodesById, string $fromNodeId, string $relation, string $direction): ?array
    {
        $candidates = [];

        foreach ($edges as $edge) {
            if ($edge['relation'] !== $relation) {
                continue;
            }

            if ($direction === 'outgoing' && $edge['from'] === $fromNodeId) {
                $target = $nodesById[$edge['to']] ?? null;
                if ($target !== null) {
                    $candidates[] = $target;
                }
            } elseif ($direction === 'incoming' && $edge['to'] === $fromNodeId) {
                $target = $nodesById[$edge['from']] ?? null;
                if ($target !== null) {
                    $candidates[] = $target;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => $a['label'] <=> $b['label']);

        return $candidates[0];
    }

    /**
     * @param array<string, mixed> $node
     * @return list<LearningCitation>
     */
    private function citationsForNode(array $node): array
    {
        $citations = [];
        foreach ($node['sources'] ?? [] as $source) {
            if (! is_array($source)) {
                continue;
            }
            $location = $source['location'] ?? null;
            if (! is_string($location) || $location === '') {
                continue;
            }
            $citations[] = new LearningCitation(
                path: $location,
                revisionRef: is_string($source['revision'] ?? null) ? $source['revision'] : null,
                lineStart: is_int($node['metadata']['start_line'] ?? null) ? $node['metadata']['start_line'] : null,
                lineEnd: is_int($node['metadata']['end_line'] ?? null) ? $node['metadata']['end_line'] : null,
            );
        }

        return $citations;
    }

    /**
     * Pick the node whose key best matches the seed concept string.
     * Prefer an exact key match, then an exact label match, then first result.
     *
     * @param list<array<string, mixed>> $nodes
     * @return array<string, mixed>
     */
    private function bestSeedMatch(array $nodes, string $seed): array
    {
        $lower = strtolower($seed);

        foreach ($nodes as $node) {
            if (strtolower($node['key'] ?? '') === $lower) {
                return $node;
            }
        }

        foreach ($nodes as $node) {
            if (strtolower($node['label'] ?? '') === $lower) {
                return $node;
            }
        }

        return $nodes[0];
    }
}
