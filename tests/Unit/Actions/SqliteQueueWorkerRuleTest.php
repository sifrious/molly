<?php

use Sifrious\Molly\Actions\ManageWorker;

/*
 * Laravel applies SQLite transaction_mode only on PHP 8.4 and later, so below that a database
 * queue on SQLite allows one worker at a time. The rule takes the PHP version as an argument,
 * so every lane checks both sides of 8.4.
 */
it('allows one worker only for a database queue on SQLite below PHP 8.4', function (int $php, ?string $queue, ?string $database, bool $oneWorker): void {
    expect(ManageWorker::sqliteQueueAllowsOneWorker($php, $queue, $database))->toBe($oneWorker);
})->with([
    'PHP 8.3.0, database queue on SQLite' => [80300, 'database', 'sqlite', true],
    'PHP 8.3.99, database queue on SQLite' => [80399, 'database', 'sqlite', true],
    'PHP 8.4.0, database queue on SQLite' => [80400, 'database', 'sqlite', false],
    'PHP 8.5.0, database queue on SQLite' => [80500, 'database', 'sqlite', false],
    'PHP 8.3, database queue on MySQL' => [80300, 'database', 'mysql', false],
    'PHP 8.3, database queue on PostgreSQL' => [80300, 'database', 'pgsql', false],
    'PHP 8.3, Redis queue' => [80300, 'redis', null, false],
    'PHP 8.3, sync queue with a SQLite default database' => [80300, 'sync', 'sqlite', false],
    'PHP 8.3, unknown queue connection' => [80300, null, null, false],
]);
