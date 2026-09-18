<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphEdge;
use Sifrious\Molly\Knowledge\GraphNode;
use Sifrious\Molly\Knowledge\GraphSource;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

class IndexProjectGraph
{
    public function __construct(private Graph $graph) {}

    /** @return array<string, mixed> */
    public function handle(string $workspace): array
    {
        $root = (new Workspace($workspace))->path;
        $version = $this->version($root);
        $tasks = Task::where('workspace', $root)->with('runs')->get();
        $source = new GraphSource(
            'project',
            $version,
            'workspace',
            $root,
            'Molly project graph',
            $root,
            null,
            hash('sha256', $root),
            ['workspace' => $root],
        );
        $nodes = [];
        $edges = [];
        $workspaceNode = $this->node($nodes, 'workspace', $root, 'Workspace', $source);
        foreach ($tasks as $task) {
            $taskNode = $this->node($nodes, 'task', $task->id, $task->nickname ?? $task->id, $source, [
                'status' => $task->status,
                'nickname' => $task->nickname,
            ]);
            $this->edge($edges, 'runs_in', $taskNode, $workspaceNode, $source);
            $testNode = $this->node($nodes, 'acceptance_test', $task->id.':'.$task->test_path, $task->test_path, $source, [
                'digest' => $task->test_digest,
                'writable' => (bool) $task->allow_test_edits,
            ]);
            $this->edge($edges, 'verified_by', $taskNode, $testNode, $source);
            foreach ($task->paths as $path) {
                $fileNode = $this->node($nodes, 'file', $path, $path, $source);
                $this->edge($edges, 'changes', $taskNode, $fileNode, $source);
            }
            if (is_string($task->source['issue_url'] ?? null)) {
                $issueNode = $this->node($nodes, 'github_issue', $task->source['issue_url'], $task->source['issue_url'], $source, [
                    'repository' => $task->source['repository'] ?? null,
                    'issue_number' => $task->source['issue_number'] ?? null,
                ]);
                $this->edge($edges, 'implements', $taskNode, $issueNode, $source);
            }
            foreach ($task->runs as $run) {
                $this->indexRun($nodes, $edges, $source, $taskNode, $testNode, $run);
            }
        }

        $unique = [];
        foreach ($edges as $edge) {
            $unique[$edge->id()] = $edge;
        }
        $counts = $this->graph->replace('project', $version, [$source], array_values($nodes), array_values($unique));

        return ['namespace' => 'project', 'version' => $version, 'workspace' => $root, 'database' => $this->graph->path(), ...$counts];
    }

    private function version(string $workspace): string
    {
        return substr(hash('sha256', $workspace), 0, 12);
    }

    /**
     * @param  array<string, GraphNode>  $nodes
     * @param  list<GraphEdge>  $edges
     */
    private function indexRun(array &$nodes, array &$edges, GraphSource $source, GraphNode $taskNode, GraphNode $testNode, Run $run): void
    {
        $runNode = $this->node($nodes, 'run', $run->id, 'Attempt '.$run->id, $source, ['status' => $run->status]);
        $this->edge($edges, 'produced', $taskNode, $runNode, $source);
        $report = $run->report ?? [];
        foreach ($report['verification_outcomes'] ?? [] as $name => $outcome) {
            if (! is_string($name) || ! is_array($outcome)) {
                continue;
            }
            $evidenceNode = $this->node($nodes, 'verifier_evidence', $run->id.':'.$name, $name, $source, [
                'state' => $outcome['state'] ?? null,
                'policy' => $outcome['policy'] ?? null,
                'failure_action' => $outcome['failure_action'] ?? null,
            ]);
            $this->edge($edges, 'produced', $runNode, $evidenceNode, $source);
            if (($outcome['state'] ?? null) !== 'PASS' && ($outcome['policy'] ?? null) === 'required') {
                $blocker = $this->node($nodes, 'blocker', $run->id.':'.$name, $name.' blocked completion', $source, [
                    'verifier' => $name,
                    'state' => $outcome['state'] ?? null,
                ]);
                $this->edge($edges, 'blocked_by', $runNode, $blocker, $source);
            }
        }
        foreach ($report['completion_blockers'] ?? [] as $name) {
            if (! is_string($name)) {
                continue;
            }
            $blocker = $this->node($nodes, 'blocker', $run->id.':'.$name, $name.' blocked completion', $source, ['verifier' => $name]);
            $this->edge($edges, 'blocked_by', $runNode, $blocker, $source);
        }
        $this->edge($edges, 'verified_by', $runNode, $testNode, $source);
        foreach ($report['changes'] ?? [] as $change) {
            if (! is_array($change) || ! is_string($change['path'] ?? null)) {
                continue;
            }
            $fileNode = $this->node($nodes, 'file', $change['path'], $change['path'], $source);
            $this->edge($edges, 'changes', $runNode, $fileNode, $source);
        }
        if (is_string($report['protected_test']['digest'] ?? null) && $report['protected_test']['digest'] !== ($testNode->metadata['digest'] ?? null)) {
            $blocker = $this->node($nodes, 'blocker', $run->id.':protected_test', 'Protected test digest changed', $source);
            $this->edge($edges, 'blocked_by', $runNode, $blocker, $source);
        }
    }

    /**
     * @param  array<string, GraphNode>  $nodes
     * @param  array<string, mixed>  $metadata
     */
    private function node(array &$nodes, string $type, string $key, string $label, GraphSource $source, array $metadata = []): GraphNode
    {
        $node = new GraphNode('project', substr(hash('sha256', $source->key), 0, 12), $type, $key, $label, [$source->id()], $metadata);
        $nodes[$node->id()] = $node;

        return $node;
    }

    /**
     * @param  list<GraphEdge>  $edges
     */
    private function edge(array &$edges, string $relation, GraphNode $from, GraphNode $to, GraphSource $source): void
    {
        $edges[] = new GraphEdge('project', $from->version, $relation, $from->id(), $to->id(), [$source->id()]);
    }
}
