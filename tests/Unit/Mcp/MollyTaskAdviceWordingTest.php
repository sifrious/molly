<?php

it('decouples molly_task advice schema wording from TypeSafe transport', function () {
    $source = file_get_contents(dirname(__DIR__, 3).'/src/Mcp/MollyTask.php');

    expect($source)->not->toContain('TypeSafe evaluation')
        ->and($source)->not->toContain('may request optional TypeSafe')
        ->and($source)->toContain('optional default-off Jev classification through Laravel AI')
        ->and($source)->toContain('advisory and state-preserving')
        ->and($source)->toContain('does not promise a provider request')
        ->and($source)->toContain("['advice' => app(RecommendTaskNextStep::class)->handle(\$data['id'])]");
});
