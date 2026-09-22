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
