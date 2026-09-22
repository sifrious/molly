<?php

namespace Sifrious\Molly\Knowledge;

use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;

final class Graph
{
    private ?PDO $connection = null;

    public function __construct(
        private GraphSchema $schema,
        private ?string $database = null,
    ) {}

    /**
     * Replace one namespace and version with a complete snapshot.
     *
     * @param  list<GraphSource>  $sources
     * @param  list<GraphNode>  $nodes
     * @param  list<GraphEdge>  $edges
     * @return array{sources: int, nodes: int, edges: int}
     */
    public function replace(string $namespace, string $version, array $sources, array $nodes, array $edges): array
    {
        // Validate the complete snapshot before opening the replacement transaction.
        $snapshot = new GraphSnapshot($namespace, $version, $sources, $nodes, $edges);
        $database = $this->connection();

        $database->beginTransaction();

        try {
            foreach (['edges', 'nodes', 'sources'] as $table) {
                $statement = $database->prepare("DELETE FROM {$table} WHERE namespace = :namespace AND version = :version");
                $statement->execute(compact('namespace', 'version'));
            }

            $this->insertSources($database, $snapshot->sources);
            $this->insertNodes($database, $snapshot->nodes);
            $this->insertEdges($database, $snapshot->edges);
            $database->commit();
        } catch (\Throwable $exception) {
            $database->rollBack();
            throw $exception;
        }

        return ['sources' => count($snapshot->sources), 'nodes' => count($snapshot->nodes), 'edges' => count($snapshot->edges)];
    }

    public function query(GraphQuery $query): GraphResult
    {
        $database = $this->connection();
        $seeds = $this->seedIds($database, $query);

        if ($seeds === []) {
            return new GraphResult($query->namespace, $query->version, $query->concept, [], [], false);
        }

        $visited = [];
        $frontier = array_fill_keys($seeds, true);
        $edgeRows = [];
        $truncated = false;

        for ($depth = 0; $depth <= $query->depth && $frontier !== []; $depth++) {
            foreach (array_keys($frontier) as $id) {
                if (! isset($visited[$id]) && count($visited) < $query->limit) {
                    $visited[$id] = true;
                } elseif (! isset($visited[$id])) {
                    $truncated = true;
                }
            }

            if ($depth === $query->depth) {
                break;
            }

            if (count($visited) >= $query->limit) {
                foreach ($this->edgesFor($database, $query, array_keys($frontier)) as $edge) {
                    if (! isset($visited[$edge['from_node_id']]) || ! isset($visited[$edge['to_node_id']])) {
                        $truncated = true;
                        break;
                    }
                }

                break;
            }

            $next = [];
            foreach ($this->edgesFor($database, $query, array_keys($frontier)) as $edge) {
                $edgeRows[$edge['id']] = $edge;
                foreach ([$edge['from_node_id'], $edge['to_node_id']] as $nodeId) {
                    if (! isset($visited[$nodeId])) {
                        $next[$nodeId] = true;
                    }
                }
            }
            $frontier = $next;
        }

        $nodeRows = $this->nodesById($database, array_keys($visited));
        $included = array_fill_keys(array_column($nodeRows, 'id'), true);
        $edges = array_values(array_filter($edgeRows, fn (array $edge): bool => isset($included[$edge['from_node_id']], $included[$edge['to_node_id']])));
        usort($edges, fn (array $left, array $right): int => [$left['relation'], $left['id']] <=> [$right['relation'], $right['id']]);

        return new GraphResult(
            $query->namespace,
            $query->version,
            $query->concept,
            $this->withSources($database, 'node_sources', 'node_id', $nodeRows),
            $this->withSources($database, 'edge_sources', 'edge_id', $edges, true),
            $truncated,
        );
    }

    /**
     * Bounded workspace overview. Blockers and tasks come first so missing
     * verification stays visible when the snapshot is truncated.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, truncated: bool}
     */
    public function overview(string $namespace, string $version, int $limit = 40): array
    {
        if ($limit < 1 || $limit > 40) {
            throw new RuntimeException('KNOWLEDGE_LIMIT_INVALID: Limit must be between 1 and 40.');
        }

        $database = $this->connection();
        $statement = $database->prepare('SELECT * FROM nodes WHERE namespace = :namespace AND version = :version ORDER BY CASE type WHEN \'blocker\' THEN 0 WHEN \'task\' THEN 1 WHEN \'run\' THEN 2 WHEN \'acceptance_test\' THEN 3 ELSE 4 END, type, label, id');
        $statement->execute(compact('namespace', 'version'));
        $rows = $statement->fetchAll();
        $truncated = count($rows) > $limit;
        $chosen = array_slice($rows, 0, $limit);
        $included = array_fill_keys(array_column($chosen, 'id'), true);
        $edgeStatement = $database->prepare('SELECT * FROM edges WHERE namespace = :namespace AND version = :version ORDER BY relation, id');
        $edgeStatement->execute(compact('namespace', 'version'));
        $edges = array_values(array_filter(
            $edgeStatement->fetchAll(),
            fn (array $edge): bool => isset($included[$edge['from_node_id']], $included[$edge['to_node_id']]),
        ));

        return [
            'nodes' => $this->withSources($database, 'node_sources', 'node_id', $chosen),
            'edges' => $this->withSources($database, 'edge_sources', 'edge_id', $edges, true),
            'truncated' => $truncated,
        ];
    }

    /** @return array{sources: int, nodes: int, edges: int} */
    public function counts(string $namespace, string $version): array
    {
        $counts = [];
        foreach (['sources', 'nodes', 'edges'] as $table) {
            $statement = $this->connection()->prepare("SELECT COUNT(*) FROM {$table} WHERE namespace = :namespace AND version = :version");
            $statement->execute(compact('namespace', 'version'));
            $counts[$table] = (int) $statement->fetchColumn();
        }

        return $counts;
    }

    public function path(): string
    {
        $path = $this->database ?? (string) config('molly.knowledge.database', '.molly/knowledge.sqlite');
        if (trim($path) === '') {
            throw new RuntimeException('KNOWLEDGE_DATABASE_INVALID: Configure a database path.');
        }

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function connection(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        File::ensureDirectoryExists(dirname($this->path()));
        $this->connection = new PDO('sqlite:'.$this->path(), options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->schema->configureConnection($this->connection);
        $this->schema->migrate($this->connection);
        $this->schema->assertCompatible($this->connection);

        return $this->connection;
    }

    /** @param  list<GraphSource>  $sources */
    private function insertSources(PDO $database, array $sources): void
    {
        $statement = $database->prepare('INSERT INTO sources (id, namespace, version, type, source_key, title, location, revision, digest, metadata) VALUES (:id, :namespace, :version, :type, :source_key, :title, :location, :revision, :digest, :metadata)');
        foreach ($sources as $source) {
            $statement->execute([
                'id' => $source->id(), 'namespace' => $source->namespace, 'version' => $source->version,
                'type' => $source->type, 'source_key' => $source->key, 'title' => $source->title,
                'location' => $source->location, 'revision' => $source->revision, 'digest' => $source->digest,
                'metadata' => $this->json($source->metadata),
            ]);
        }
    }

    /** @param  list<GraphNode>  $nodes */
    private function insertNodes(PDO $database, array $nodes): void
    {
        $node = $database->prepare('INSERT INTO nodes (id, namespace, version, type, node_key, label, metadata) VALUES (:id, :namespace, :version, :type, :node_key, :label, :metadata)');
        $link = $database->prepare('INSERT INTO node_sources (node_id, source_id) VALUES (:node_id, :source_id)');
        foreach ($nodes as $record) {
            $node->execute([
                'id' => $record->id(), 'namespace' => $record->namespace, 'version' => $record->version,
                'type' => $record->type, 'node_key' => $record->key, 'label' => $record->label,
                'metadata' => $this->json($record->metadata),
            ]);
            foreach (array_values(array_unique($record->sourceIds)) as $sourceId) {
                $link->execute(['node_id' => $record->id(), 'source_id' => $sourceId]);
            }
        }
    }

    /** @param  list<GraphEdge>  $edges */
    private function insertEdges(PDO $database, array $edges): void
    {
        $edge = $database->prepare('INSERT INTO edges (id, namespace, version, relation, from_node_id, to_node_id, metadata) VALUES (:id, :namespace, :version, :relation, :from, :to, :metadata)');
        $link = $database->prepare('INSERT INTO edge_sources (edge_id, source_id) VALUES (:edge_id, :source_id)');
        foreach ($edges as $record) {
            $edge->execute([
                'id' => $record->id(), 'namespace' => $record->namespace, 'version' => $record->version,
                'relation' => $record->relation, 'from' => $record->from, 'to' => $record->to,
                'metadata' => $this->json($record->metadata),
            ]);
            foreach (array_values(array_unique($record->sourceIds)) as $sourceId) {
                $link->execute(['edge_id' => $record->id(), 'source_id' => $sourceId]);
            }
        }
    }

    /** @return list<string> */
    private function seedIds(PDO $database, GraphQuery $query): array
    {
        $statement = $database->prepare('SELECT id FROM nodes WHERE namespace = :namespace AND version = :version AND (lower(node_key) = lower(:concept) OR lower(label) = lower(:concept)) ORDER BY type, node_key LIMIT 5');
        $statement->execute(['namespace' => $query->namespace, 'version' => $query->version, 'concept' => $query->concept]);
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);

        if ($ids !== []) {
            return $ids;
        }

        $statement = $database->prepare("SELECT id FROM nodes WHERE namespace = :namespace AND version = :version AND (lower(node_key) LIKE lower(:term) ESCAPE '\\' OR lower(label) LIKE lower(:term) ESCAPE '\\') ORDER BY type, node_key LIMIT 5");
        $statement->execute([
            'namespace' => $query->namespace,
            'version' => $query->version,
            'term' => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query->concept).'%',
        ]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param  list<string>  $nodeIds
     * @return list<array<string, mixed>>
     */
    private function edgesFor(PDO $database, GraphQuery $query, array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $parameters = ['namespace' => $query->namespace, 'version' => $query->version];
        $fromPlaceholders = $this->placeholders('from_node', $nodeIds, $parameters);
        $toPlaceholders = $this->placeholders('to_node', $nodeIds, $parameters);
        $relationSql = '';
        if ($query->relations !== []) {
            $relationSql = ' AND relation IN ('.$this->placeholders('relation', $query->relations, $parameters).')';
        }
        $statement = $database->prepare("SELECT * FROM edges WHERE namespace = :namespace AND version = :version AND (from_node_id IN ({$fromPlaceholders}) OR to_node_id IN ({$toPlaceholders})){$relationSql} ORDER BY relation, id");
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function nodesById(PDO $database, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $parameters = [];
        $statement = $database->prepare('SELECT * FROM nodes WHERE id IN ('.$this->placeholders('node', $ids, $parameters).') ORDER BY type, label, id');
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    private const SOURCE_BATCH_SIZE = 400;

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function withSources(PDO $database, string $pivot, string $foreignKey, array $records, bool $edge = false): array
    {
        if ($records === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map(fn (array $record): string => $record['id'], $records)));
        /** @var array<string, list<array<string, mixed>>> $sourcesByOwner */
        $sourcesByOwner = [];
        foreach ($ids as $id) {
            $sourcesByOwner[$id] = [];
        }

        foreach (array_chunk($ids, self::SOURCE_BATCH_SIZE) as $chunk) {
            $parameters = [];
            $sql = "SELECT {$pivot}.{$foreignKey} AS owner_id, sources.* FROM sources JOIN {$pivot} ON {$pivot}.source_id = sources.id WHERE {$pivot}.{$foreignKey} IN (".$this->placeholders('owner', $chunk, $parameters).') ORDER BY sources.type, sources.source_key, sources.id';
            $statement = $database->prepare($sql);
            $statement->execute($parameters);
            foreach ($statement->fetchAll() as $source) {
                $ownerId = $source['owner_id'];
                unset($source['owner_id']);
                $sourcesByOwner[$ownerId][] = [
                    'id' => $source['id'],
                    'namespace' => $source['namespace'],
                    'version' => $source['version'],
                    'type' => $source['type'],
                    'key' => $source['source_key'],
                    'title' => $source['title'],
                    'location' => $source['location'],
                    'revision' => $source['revision'],
                    'digest' => $source['digest'],
                    'metadata' => $this->decode($source['metadata']),
                ];
            }
        }

        return array_map(function (array $record) use ($sourcesByOwner, $edge): array {
            $sources = $sourcesByOwner[$record['id']] ?? [];

            return $edge ? [
                'id' => $record['id'],
                'relation' => $record['relation'],
                'from' => $record['from_node_id'],
                'to' => $record['to_node_id'],
                'metadata' => $this->decode($record['metadata']),
                'sources' => $sources,
            ] : [
                'id' => $record['id'],
                'type' => $record['type'],
                'key' => $record['node_key'],
                'label' => $record['label'],
                'metadata' => $this->decode($record['metadata']),
                'sources' => $sources,
            ];
        }, $records);
    }

    /** @param  list<string>  $values
     * @param  array<string, string>  $parameters
     */
    private function placeholders(string $prefix, array $values, array &$parameters): string
    {
        $placeholders = [];
        foreach (array_values($values) as $index => $value) {
            $name = $prefix.$index;
            $placeholders[] = ':'.$name;
            $parameters[$name] = $value;
        }

        return implode(', ', $placeholders);
    }

    /** @param  array<string, mixed>  $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    private function decode(string $value): array
    {
        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }
}
