<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * M02.4: SQLite runs no Molly migration in a transaction, and Laravel records a migration only
 * after it finishes, so php artisan migrate killed part-way leaves some of a migration's
 * statements applied and the migration unrecorded. Each case below rebuilds one such state by
 * replaying the first statements a migration issues, then runs migrate again, which must finish
 * with the same schema as an uninterrupted install.
 */

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/molly-migrate-'.Str::uuid();
    File::ensureDirectoryExists($this->directory);
    $this->migrations = realpath(__DIR__.'/../../database/migrations');
});

afterEach(function (): void {
    DB::purge('interrupted');
    File::deleteDirectory($this->directory);
});

/** Point the interrupted connection at a new, empty SQLite file. */
function freshInterruptedDatabase(string $directory): void
{
    $file = $directory.'/'.Str::uuid().'.sqlite';
    touch($file);
    DB::purge('interrupted');
    config(['database.connections.interrupted' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]]);
}

function migrateInterrupted(string $path): int
{
    return Artisan::call('migrate', ['--database' => 'interrupted', '--path' => $path, '--realpath' => true, '--force' => true]);
}

/** Tables, columns, indexes, and foreign keys, including any __temp__ copy left behind. */
function interruptedSchema(): array
{
    $schema = Schema::connection('interrupted');
    $tables = collect($schema->getTables())->pluck('name')
        ->filter(fn (string $table): bool => str_contains($table, 'molly_'))->sort()->values();

    return $tables->mapWithKeys(fn (string $table): array => [$table => [
        'columns' => collect($schema->getColumns($table))->map(fn (array $column): array => Arr::only($column, ['name', 'type', 'nullable', 'default']))->sortBy('name')->values()->all(),
        'indexes' => collect($schema->getIndexes($table))->map(fn (array $index): array => Arr::only($index, ['name', 'columns', 'unique', 'primary']))->sortBy('name')->values()->all(),
        'foreign_keys' => collect($schema->getForeignKeys($table))->map(fn (array $key): array => Arr::only($key, ['columns', 'foreign_table', 'foreign_columns', 'on_delete']))->sortBy('columns')->values()->all(),
    ]])->all();
}

/**
 * Run each migration on its own and record the statements that change the schema, in order.
 *
 * @return array<string, list<string>> migration name => statements
 */
function recordMigrationStatements(string $migrations): array
{
    $current = null;
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$current, &$statements): void {
        $sql = trim($query->sql);
        if ($current !== null && $query->connectionName === 'interrupted' && ! str_contains($sql, '"migrations"')
            && preg_match('/\A(create|alter|drop|insert into "__temp__|pragma foreign_keys\s*=)/i', $sql) === 1) {
            $statements[$current][] = $sql;
        }
    });

    foreach (File::glob($migrations.'/*.php') as $file) {
        $current = basename($file, '.php');
        $statements[$current] = [];
        expect(migrateInterrupted($file))->toBe(0);
    }
    $current = null;

    return $statements;
}

it('finishes php artisan migrate after it was stopped between any two statements of a Molly migration', function (): void {
    freshInterruptedDatabase($this->directory);
    $statements = recordMigrationStatements($this->migrations);
    $expected = interruptedSchema();
    expect($statements)->toHaveCount(12)
        ->and(collect($statements)->flatten()->filter(fn (string $sql): bool => str_contains($sql, '__temp__molly_runs')))->not->toBeEmpty();

    $names = array_keys($statements);
    $cases = 0;
    foreach ($names as $position => $name) {
        // Stopping after the last statement leaves the whole migration applied but not recorded.
        for ($applied = 1; $applied <= count($statements[$name]); $applied++) {
            freshInterruptedDatabase($this->directory);
            Artisan::call('migrate:install', ['--database' => 'interrupted']);
            $connection = DB::connection('interrupted');
            foreach (array_slice($names, 0, $position) as $done) {
                foreach ($statements[$done] as $sql) {
                    $connection->statement($sql);
                }
                $connection->table('migrations')->insert(['migration' => $done, 'batch' => 1]);
            }
            foreach (array_slice($statements[$name], 0, $applied) as $sql) {
                $connection->statement($sql);
            }

            $case = $name.' stopped after statement '.$applied.' of '.count($statements[$name]);
            expect(migrateInterrupted($this->migrations))->toBe(0, $case.': '.Artisan::output())
                ->and(interruptedSchema())->toBe($expected, $case)
                ->and($connection->table('migrations')->pluck('migration')->all())->toBe($names, $case);
            $cases++;
        }
    }

    expect($cases)->toBeGreaterThan(40);
});

it('finishes migrate when molly_runs was dropped before its SQLite copy was renamed into place', function (): void {
    freshInterruptedDatabase($this->directory);
    $statements = recordMigrationStatements($this->migrations);
    $expected = interruptedSchema();

    freshInterruptedDatabase($this->directory);
    Artisan::call('migrate:install', ['--database' => 'interrupted']);
    $connection = DB::connection('interrupted');
    [$runs, $tasks] = array_keys($statements);
    foreach ($statements[$runs] as $sql) {
        $connection->statement($sql);
    }
    $connection->table('migrations')->insert(['migration' => $runs, 'batch' => 1]);
    $rename = collect($statements[$tasks])->search(fn (string $sql): bool => str_starts_with($sql, 'alter table "__temp__molly_runs" rename'));
    expect($rename)->toBeInt();
    foreach (array_slice($statements[$tasks], 0, $rename) as $sql) {
        $connection->statement($sql);
    }
    $connection->table('molly_tasks')->insert(['id' => $task = (string) Str::uuid(), 'prompt' => 'Return Hello.', 'workspace' => '/tmp/app', 'paths' => '[]', 'test_path' => 'tests/HelloTest.php']);
    $connection->table('__temp__molly_runs')->insert(['id' => $run = (string) Str::uuid(), 'task_id' => $task, 'prompt' => 'Return Hello.', 'workspace' => '/tmp/app', 'status' => 'failed', 'report' => '{}']);
    expect(Schema::connection('interrupted')->hasTable('molly_runs'))->toBeFalse();

    expect(migrateInterrupted($this->migrations))->toBe(0, Artisan::output())
        ->and(interruptedSchema())->toBe($expected)
        ->and($connection->table('molly_runs')->where('id', $run)->value('task_id'))->toBe($task);
});

it('rolls every Molly migration back and applies it again', function (): void {
    freshInterruptedDatabase($this->directory);
    expect(migrateInterrupted($this->migrations))->toBe(0);
    $expected = interruptedSchema();

    expect(Artisan::call('migrate:rollback', ['--database' => 'interrupted', '--path' => $this->migrations, '--realpath' => true, '--force' => true]))->toBe(0, Artisan::output())
        ->and(interruptedSchema())->toBe([])
        ->and(migrateInterrupted($this->migrations))->toBe(0)
        ->and(interruptedSchema())->toBe($expected);
});
