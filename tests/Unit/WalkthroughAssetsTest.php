<?php

it('ships walkthrough screenshots and documents regeneration', function (): void {
    $root = dirname(__DIR__, 2);
    $dir = $root.'/docs/v0.1/walkthrough';
    $names = [
        '01-tasks-index.png',
        '02-task-demo-greeting.png',
        '03-run-show.png',
        '04-task-create.png',
    ];

    foreach ($names as $name) {
        $path = $dir.'/'.$name;
        expect(is_file($path))->toBeTrue("missing $name")
            ->and(filesize($path))->toBeGreaterThan(10_000);
    }

    $walk = file_get_contents($root.'/docs/v0.1/WALKTHROUGH.md');
    expect($walk)->toContain('bin/molly-docs-walkthrough')
        ->and($walk)->toContain('MOLLY_SANDBOX_ALLOW_UNSAFE')
        ->and($walk)->toContain('Bloom')
        ->and($walk)->toContain('walkthrough/01-tasks-index.png');

    $quick = file_get_contents($root.'/docs/v0.1/QUICKSTART.md');
    expect($quick)->toContain('WALKTHROUGH.md');

    expect(is_executable($root.'/bin/molly-docs-walkthrough'))->toBeTrue();
});
