<?php

namespace Sifrious\Molly\Tests\Support;

use PDO;
use PHPUnit\Framework\Assert;
use Sifrious\Molly\Knowledge\GraphEdge;
use Sifrious\Molly\Knowledge\GraphNode;
use Sifrious\Molly\Knowledge\GraphSource;

/**
 * Reviewable golden snapshots of Molly's graphs.
 *
 * A snapshot names each source and node by "type:key" instead of its hash, lists every
 * record on one line, and sorts the records, so a wrong edge shows up as one changed
 * line. A reflected Laravel file's digest, line numbers, and package version come from
 * the installed laravel/framework release rather than from Molly, so they are left out
 * and the same snapshot holds on every CI lane.
 *
 * Tests compare against tests/Fixtures/graphs and never write there unless
 * MOLLY_UPDATE_GRAPH_GOLDENS=1 is set. docs/contributing.md describes the update.
 */
final class GraphGolden
{
    public const UPDATE_FLAG = 'MOLLY_UPDATE_GRAPH_GOLDENS';

    private const REFLECTED_NODE_METADATA = ['digest', 'end_line', 'start_line'];

    /**
     * @param  array{sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}  $graph
     * @param  array<string, string>  $replace  run-specific values, such as a temporary path, and their placeholders
     * @return array{namespace: string, version: string, sources: list<array<string, mixed>>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function fromRecords(string $namespace, string $version, array $graph, array $replace = []): array
    {
        return self::normalize(
            $namespace,
            $version,
            array_map(fn (GraphSource $source): array => [
                'id' => $source->id(), 'type' => $source->type, 'key' => $source->key, 'title' => $source->title,
                'location' => $source->location, 'revision' => $source->revision, 'digest' => $source->digest, 'metadata' => $source->metadata,
            ], $graph['sources']),
            array_map(fn (GraphNode $node): array => [
                'id' => $node->id(), 'type' => $node->type, 'key' => $node->key, 'label' => $node->label,
                'sources' => $node->sourceIds, 'metadata' => $node->metadata,
            ], $graph['nodes']),
            array_map(fn (GraphEdge $edge): array => [
                'relation' => $edge->relation, 'from' => $edge->from, 'to' => $edge->to,
                'sources' => $edge->sourceIds, 'metadata' => $edge->metadata,
            ], $graph['edges']),
            $replace,
        );
    }

    /**
     * Read one namespace and version back from a graph store.
     *
     * @param  array<string, string>  $replace
     * @return array{namespace: string, version: string, sources: list<array<string, mixed>>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function fromDatabase(string $database, string $namespace, string $version, array $replace = []): array
    {
        $pdo = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $rows = function (string $table) use ($pdo, $namespace, $version): array {
            $statement = $pdo->prepare("SELECT * FROM {$table} WHERE namespace = :namespace AND version = :version");
            $statement->execute(['namespace' => $namespace, 'version' => $version]);

            return $statement->fetchAll();
        };
        $links = function (string $table, string $column) use ($pdo): array {
            $sources = [];
            foreach ($pdo->query("SELECT {$column}, source_id FROM {$table}")->fetchAll() as $row) {
                $sources[$row[$column]][] = $row['source_id'];
            }

            return $sources;
        };
        $nodeSources = $links('node_sources', 'node_id');
        $edgeSources = $links('edge_sources', 'edge_id');

        return self::normalize(
            $namespace,
            $version,
            array_map(fn (array $row): array => [
                'id' => $row['id'], 'type' => $row['type'], 'key' => $row['source_key'], 'title' => $row['title'],
                'location' => $row['location'], 'revision' => $row['revision'], 'digest' => $row['digest'], 'metadata' => json_decode($row['metadata'], true),
            ], $rows('sources')),
            array_map(fn (array $row): array => [
                'id' => $row['id'], 'type' => $row['type'], 'key' => $row['node_key'], 'label' => $row['label'],
                'sources' => $nodeSources[$row['id']] ?? [], 'metadata' => json_decode($row['metadata'], true),
            ], $rows('nodes')),
            array_map(fn (array $row): array => [
                'relation' => $row['relation'], 'from' => $row['from_node_id'], 'to' => $row['to_node_id'],
                'sources' => $edgeSources[$row['id']] ?? [], 'metadata' => json_decode($row['metadata'], true),
            ], $rows('edges')),
            $replace,
        );
    }

    /**
     * The nodes one node reaches over a relation, as "type:key" references.
     *
     * @return list<string>
     */
    public static function targets(array $graph, string $from, string $relation): array
    {
        $targets = [];
        foreach ($graph['edges'] as $edge) {
            if ($edge['from'] === $from && $edge['relation'] === $relation) {
                $targets[] = $edge['to'];
            }
        }
        sort($targets);

        return $targets;
    }

    /**
     * Assert the exact targets of one relationship, naming the relationship when it is wrong.
     *
     * @param  list<string>  $expected
     */
    public static function assertEdges(array $graph, string $from, string $relation, array $expected): void
    {
        sort($expected);
        $actual = self::targets($graph, $from, $relation);
        Assert::assertSame($expected, $actual, sprintf(
            'Wrong %s edge: %s must point at %s, but the graph points it at %s.',
            $relation,
            $from,
            implode(', ', $expected),
            $actual === [] ? 'nothing' : implode(', ', $actual),
        ));
    }

    /** Compare a snapshot with its golden file, or write the file when MOLLY_UPDATE_GRAPH_GOLDENS=1. */
    public static function assertMatchesFile(array $graph, string $name): void
    {
        $relative = 'tests/Fixtures/graphs/'.$name.'.json';
        $path = dirname(__DIR__, 2).'/'.$relative;
        $actual = self::encode($graph);
        if (getenv(self::UPDATE_FLAG) === '1') {
            is_dir(dirname($path)) || mkdir(dirname($path), 0755, true);
            file_put_contents($path, $actual);
            Assert::markTestIncomplete("Wrote {$relative}. Review the diff, then run the suite without ".self::UPDATE_FLAG.'.');
        }
        if (! is_file($path)) {
            Assert::fail("{$relative} does not exist. Run ".self::UPDATE_FLAG.'=1 vendor/bin/pest --group=graph-golden to write it, then review it.');
        }
        Assert::assertSame((string) file_get_contents($path), $actual, "The {$name} snapshot no longer matches {$relative}. Each line is one record, such as a source, node, or edge. If the change is intended, run ".self::UPDATE_FLAG.'=1 vendor/bin/pest --group=graph-golden and review the diff.');
    }

    /** JSON with each top-level list written one record per line, so a diff names the record that changed. */
    public static function encode(array $graph): string
    {
        $json = fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $fields = [];
        foreach ($graph as $key => $value) {
            $encoded = $json($value);
            if (is_array($value) && $value !== [] && array_is_list($value)) {
                $encoded = "[\n    ".implode(",\n    ", array_map($json, $value))."\n  ]";
            }
            $fields[] = '  '.$json((string) $key).': '.$encoded;
        }

        return "{\n".implode(",\n", $fields)."\n}\n";
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, string>  $replace
     */
    private static function normalize(string $namespace, string $version, array $sources, array $nodes, array $edges, array $replace): array
    {
        $sourceRefs = [];
        $reflected = [];
        foreach ($sources as $source) {
            $sourceRefs[$source['id']] = $source['type'].':'.$source['key'];
            if ($source['type'] === 'framework_source') {
                $reflected[$source['id']] = true;
            }
        }
        $nodeRefs = [];
        foreach ($nodes as $node) {
            $nodeRefs[$node['id']] = $node['type'].':'.$node['key'];
        }
        $refs = function (array $ids, array $names): array {
            $refs = array_map(fn (string $id): string => $names[$id] ?? 'missing:'.$id, $ids);
            sort($refs);

            return $refs;
        };

        $sourceRows = array_map(function (array $source) use ($reflected): array {
            $metadata = $source['metadata'] ?? [];
            if (isset($reflected[$source['id']])) {
                unset($source['digest'], $metadata['package_version']);
            }

            return ['ref' => $source['type'].':'.$source['key'], ...array_diff_key($source, array_flip(['id', 'type', 'key', 'metadata'])), 'metadata' => self::sortKeys($metadata)];
        }, $sources);
        $nodeRows = array_map(function (array $node) use ($refs, $sourceRefs, $reflected): array {
            $metadata = $node['metadata'] ?? [];
            if ($node['sources'] !== [] && array_diff($node['sources'], array_keys($reflected)) === []) {
                $metadata = array_diff_key($metadata, array_flip(self::REFLECTED_NODE_METADATA));
            }

            return ['ref' => $node['type'].':'.$node['key'], 'label' => $node['label'], 'sources' => $refs($node['sources'], $sourceRefs), 'metadata' => self::sortKeys($metadata)];
        }, $nodes);
        $edgeRows = array_map(fn (array $edge): array => [
            'from' => $nodeRefs[$edge['from']] ?? 'missing:'.$edge['from'],
            'relation' => $edge['relation'],
            'to' => $nodeRefs[$edge['to']] ?? 'missing:'.$edge['to'],
            'sources' => $refs($edge['sources'], $sourceRefs),
            'metadata' => self::sortKeys($edge['metadata'] ?? []),
        ], $edges);

        $graph = self::replace([
            'namespace' => $namespace,
            'version' => $version,
            'sources' => $sourceRows,
            'nodes' => $nodeRows,
            'edges' => $edgeRows,
        ], $replace);
        usort($graph['sources'], fn (array $a, array $b): int => strcmp($a['ref'], $b['ref']));
        usort($graph['nodes'], fn (array $a, array $b): int => strcmp($a['ref'], $b['ref']));
        usort($graph['edges'], fn (array $a, array $b): int => [$a['from'], $a['relation'], $a['to']] <=> [$b['from'], $b['relation'], $b['to']]);

        return $graph;
    }

    /**
     * Swap run-specific values, such as a temporary path, for placeholders.
     *
     * @param  array<string, string>  $replace
     */
    private static function replace(mixed $value, array $replace): mixed
    {
        if (is_string($value)) {
            return $replace === [] ? $value : strtr($value, $replace);
        }
        if (! is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $item) {
            $result[is_string($key) ? self::replace($key, $replace) : $key] = self::replace($item, $replace);
        }

        return $result;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn (mixed $item): mixed => self::sortKeys($item), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
