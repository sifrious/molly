<?php

namespace Sifrious\Molly\Knowledge;

use PDO;
use RuntimeException;

/** Versioned SQLite DDL for the isolated Molly knowledge graph database. */
final class GraphSchema
{
    public const VERSION = 1;

    public const METADATA_KEY = 'schema_version';

    /** Full DDL applied once per connection (tables, indexes, FKs, metadata). */
    public function ddl(): string
    {
        return <<<'SQL'
            CREATE TABLE IF NOT EXISTS sources (
                id TEXT PRIMARY KEY, namespace TEXT NOT NULL, version TEXT NOT NULL,
                type TEXT NOT NULL, source_key TEXT NOT NULL, title TEXT NOT NULL,
                location TEXT, revision TEXT, digest TEXT, metadata TEXT NOT NULL
            );
            CREATE UNIQUE INDEX IF NOT EXISTS sources_scope_key ON sources(namespace, version, type, source_key);
            CREATE TABLE IF NOT EXISTS nodes (
                id TEXT PRIMARY KEY, namespace TEXT NOT NULL, version TEXT NOT NULL,
                type TEXT NOT NULL, node_key TEXT NOT NULL, label TEXT NOT NULL, metadata TEXT NOT NULL
            );
            CREATE UNIQUE INDEX IF NOT EXISTS nodes_scope_key ON nodes(namespace, version, type, node_key);
            CREATE INDEX IF NOT EXISTS nodes_label ON nodes(namespace, version, label);
            CREATE TABLE IF NOT EXISTS edges (
                id TEXT PRIMARY KEY, namespace TEXT NOT NULL, version TEXT NOT NULL,
                relation TEXT NOT NULL, from_node_id TEXT NOT NULL, to_node_id TEXT NOT NULL, metadata TEXT NOT NULL,
                FOREIGN KEY(from_node_id) REFERENCES nodes(id) ON DELETE CASCADE,
                FOREIGN KEY(to_node_id) REFERENCES nodes(id) ON DELETE CASCADE
            );
            CREATE UNIQUE INDEX IF NOT EXISTS edges_scope_key ON edges(namespace, version, relation, from_node_id, to_node_id);
            CREATE TABLE IF NOT EXISTS node_sources (
                node_id TEXT NOT NULL, source_id TEXT NOT NULL,
                PRIMARY KEY(node_id, source_id),
                FOREIGN KEY(node_id) REFERENCES nodes(id) ON DELETE CASCADE,
                FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS edge_sources (
                edge_id TEXT NOT NULL, source_id TEXT NOT NULL,
                PRIMARY KEY(edge_id, source_id),
                FOREIGN KEY(edge_id) REFERENCES edges(id) ON DELETE CASCADE,
                FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS schema_metadata (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
            SQL;
    }

    public function apply(PDO $database): void
    {
        $database->exec($this->ddl());
        $statement = $database->prepare(
            'INSERT INTO schema_metadata (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $statement->execute([
            'key' => self::METADATA_KEY,
            'value' => (string) self::VERSION,
        ]);
    }

    /** Create or upgrade forward-only; refuse newer unsupported schemas; idempotent. */
    public function migrate(PDO $database): void
    {
        $database->beginTransaction();
        try {
            $current = $this->recordedVersion($database);
            if ($current === null) {
                // Fresh or legacy without metadata table — apply full DDL + record version.
                $this->apply($database);
            } elseif ($current > self::VERSION) {
                throw new RuntimeException(
                    'KNOWLEDGE_SCHEMA_UNSUPPORTED: Knowledge database schema '.$current.' is newer than Molly supports ('.self::VERSION.').'
                );
            } elseif ($current < self::VERSION) {
                // Forward-only upgrades would run here; VERSION==1 has no intermediate steps yet.
                $this->apply($database);
            } else {
                // Already current — re-apply DDL idempotently (IF NOT EXISTS).
                $this->apply($database);
            }
            $database->commit();
        } catch (\Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }

    public function recordedVersion(PDO $database): ?int
    {
        try {
            $statement = $database->query(
                "SELECT value FROM schema_metadata WHERE key = '".self::METADATA_KEY."'"
            );
            $value = $statement?->fetchColumn();
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
