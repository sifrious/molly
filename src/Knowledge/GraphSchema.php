<?php

namespace Sifrious\Molly\Knowledge;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

/** Versioned SQLite DDL for the isolated Molly knowledge graph database. */
final class GraphSchema
{
    public const VERSION = 1;

    public const METADATA_KEY = 'schema_version';

    /** Busy wait budget for local readers/builders contending on the isolated SQLite file. */
    public const BUSY_TIMEOUT_MS = 5000;

    /**
     * Journal mode for the local knowledge database.
     * WAL lets builders and readers coexist without long exclusive locks on one machine.
     */
    public const JOURNAL_MODE = 'WAL';

    public function configureConnection(PDO $database): void
    {
        // Set the busy wait first so every later statement, including the journal switch, honours it.
        $database->exec('PRAGMA busy_timeout = '.self::BUSY_TIMEOUT_MS);
        $database->exec('PRAGMA foreign_keys = ON');
        $mode = strtolower((string) $database->query('PRAGMA journal_mode')->fetchColumn());
        if ($mode !== strtolower(self::JOURNAL_MODE)) {
            $database->exec('PRAGMA journal_mode = '.self::JOURNAL_MODE);
        }
    }

    /**
     * Run a write inside BEGIN IMMEDIATE.
     *
     * A deferred transaction that has already read cannot wait for the write lock; SQLite
     * returns SQLITE_BUSY at once to avoid a deadlock. Taking the write lock up front lets
     * the busy timeout apply.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function immediately(PDO $database, Closure $work): mixed
    {
        $database->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
            $database->exec('COMMIT');

            return $result;
        } catch (Throwable $exception) {
            try {
                $database->exec('ROLLBACK');
            } catch (Throwable) {
                // The transaction already ended; keep the original error.
            }
            throw $exception;
        }
    }

    /** Verify recorded schema is present and supported before queries or replacement writes. */
    public function assertCompatible(PDO $database): void
    {
        $current = $this->recordedVersion($database);
        if ($current === null) {
            throw new RuntimeException('KNOWLEDGE_SCHEMA_MISSING: Knowledge database has no schema_version metadata.');
        }
        if ($current > self::VERSION) {
            throw new RuntimeException(
                'KNOWLEDGE_SCHEMA_UNSUPPORTED: Knowledge database schema '.$current.' is newer than Molly supports ('.self::VERSION.').'
            );
        }
        if ($current < self::VERSION) {
            throw new RuntimeException(
                'KNOWLEDGE_SCHEMA_STALE: Knowledge database schema '.$current.' must be migrated to '.self::VERSION.' before use.'
            );
        }
    }

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

    /**
     * Create or upgrade forward-only; refuse newer unsupported schemas; idempotent.
     *
     * A database already at VERSION is only read, so readers never take a write lock.
     */
    public function migrate(PDO $database): void
    {
        $this->assertNotNewer($this->recordedVersion($database));
        if ($this->recordedVersion($database) === self::VERSION) {
            return;
        }

        $this->immediately($database, function () use ($database): void {
            // Another process may have created the schema while this one waited.
            $current = $this->recordedVersion($database);
            $this->assertNotNewer($current);
            if ($current !== self::VERSION) {
                // Forward-only upgrades would run here; VERSION 1 has no intermediate steps.
                $this->apply($database);
            }
        });
    }

    private function assertNotNewer(?int $current): void
    {
        if ($current !== null && $current > self::VERSION) {
            throw new RuntimeException(
                'KNOWLEDGE_SCHEMA_UNSUPPORTED: Knowledge database schema '.$current.' is newer than Molly supports ('.self::VERSION.').'
            );
        }
    }

    public function recordedVersion(PDO $database): ?int
    {
        try {
            $statement = $database->query(
                "SELECT value FROM schema_metadata WHERE key = '".self::METADATA_KEY."'"
            );
            $value = $statement?->fetchColumn();
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
