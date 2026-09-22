<?php

use Sifrious\Molly\Knowledge\GraphSchema;

it('owns versioned SQLite DDL and records schema metadata', function () {
    $path = sys_get_temp_dir().'/molly-graph-schema-'.uniqid('', true).'.sqlite';
    @unlink($path);

    $schema = new GraphSchema;
    $ddl = $schema->ddl();

    expect($ddl)->toContain('CREATE TABLE IF NOT EXISTS sources')
        ->and($ddl)->toContain('CREATE TABLE IF NOT EXISTS nodes')
        ->and($ddl)->toContain('CREATE TABLE IF NOT EXISTS edges')
        ->and($ddl)->toContain('CREATE TABLE IF NOT EXISTS node_sources')
        ->and($ddl)->toContain('CREATE TABLE IF NOT EXISTS edge_sources')
        ->and($ddl)->toContain('CREATE TABLE IF NOT EXISTS schema_metadata')
        ->and(GraphSchema::VERSION)->toBe(1);

    $pdo = new PDO('sqlite:'.$path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $schema->apply($pdo);

    expect($schema->recordedVersion($pdo))->toBe(GraphSchema::VERSION);

    @unlink($path);
});

it('migrates idempotently and refuses unsupported newer schemas', function () {
    $path = sys_get_temp_dir().'/molly-graph-migrate-'.uniqid('', true).'.sqlite';
    @unlink($path);
    $schema = new GraphSchema;
    $pdo = new PDO('sqlite:'.$path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $schema->migrate($pdo);
    expect($schema->recordedVersion($pdo))->toBe(GraphSchema::VERSION);
    $schema->migrate($pdo); // idempotent
    expect($schema->recordedVersion($pdo))->toBe(GraphSchema::VERSION);

    $pdo->exec("UPDATE schema_metadata SET value = '99' WHERE key = 'schema_version'");
    expect(fn () => $schema->migrate($pdo))
        ->toThrow(RuntimeException::class, 'KNOWLEDGE_SCHEMA_UNSUPPORTED:');

    @unlink($path);
});

it('configures foreign keys busy timeout and WAL then verifies compatibility', function () {
    $path = sys_get_temp_dir().'/molly-graph-pragma-'.uniqid('', true).'.sqlite';
    @unlink($path);
    $schema = new GraphSchema;
    $pdo = new PDO('sqlite:'.$path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $schema->configureConnection($pdo);
    expect($pdo->query('PRAGMA foreign_keys')->fetchColumn())->toBe(1)
        ->and((int) $pdo->query('PRAGMA busy_timeout')->fetchColumn())->toBe(GraphSchema::BUSY_TIMEOUT_MS)
        ->and(strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()))->toBe('wal');

    expect(fn () => $schema->assertCompatible($pdo))
        ->toThrow(RuntimeException::class, 'KNOWLEDGE_SCHEMA_MISSING:');

    $schema->migrate($pdo);
    $schema->assertCompatible($pdo);

    @unlink($path);
});
