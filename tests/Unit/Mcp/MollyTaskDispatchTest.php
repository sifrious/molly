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
