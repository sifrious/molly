<?php

use Sifrious\Molly\Mcp\MollyTask;

it('dispatches molly_task operations through named private methods', function () {
    $reflection = new ReflectionClass(MollyTask::class);
    foreach (['dispatchRead', 'dispatchCreate', 'dispatchLifecycle', 'dispatchHandoff', 'dispatchAdvice', 'dispatchQueue'] as $method) {
        expect($reflection->hasMethod($method))->toBeTrue()
            ->and($reflection->getMethod($method)->isPrivate())->toBeTrue();
    }

    $source = file_get_contents(dirname(__DIR__, 3).'/src/Mcp/MollyTask.php');
    expect($source)->toContain('$this->dispatchRead($data)')
        ->and($source)->toContain('$this->dispatchCreate($data)')
        ->and($source)->toContain('$this->dispatchLifecycle($data)')
        ->and($source)->toContain('$this->dispatchHandoff($data)')
        ->and($source)->toContain('$this->dispatchAdvice($data)')
        ->and($source)->toContain('$this->dispatchQueue($data)');
});

it('keeps MollyTask schema and validation operations in sync from one list', function () {
    $reflection = new ReflectionClass(MollyTask::class);
    $operations = $reflection->getConstant('OPERATIONS');
    expect($operations)->toBeArray()->not->toBeEmpty()
        ->and($operations)->toBe(array_values(array_unique($operations)));

    $source = file_get_contents(dirname(__DIR__, 3).'/src/Mcp/MollyTask.php');
    expect($source)->toContain("'in:'.implode(',', self::OPERATIONS)")
        ->and($source)->toContain('->enum(self::OPERATIONS)')
        ->and($source)->not->toContain("'in:list,show,create");

    foreach ($operations as $operation) {
        expect($source)->toContain("'".$operation."'");
    }

    $dispatchOps = [];
    foreach (['dispatchRead', 'dispatchCreate', 'dispatchLifecycle', 'dispatchHandoff'] as $method) {
        $body = $reflection->getMethod($method)->getFileName();
    }
    // Every OPERATIONS entry appears in the top-level handle match arms or as start/retry/advice
    $handle = file_get_contents($reflection->getFileName());
    foreach ($operations as $operation) {
        expect($handle)->toMatch("/'".preg_quote($operation, '/')."'/");
    }
});
