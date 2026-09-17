<?php

use Sifrious\Molly\PlanningGuide;

it('ships a small connected graph with verifiable offline citations for every planning question', function () {
    $guide = app(PlanningGuide::class);
    $graph = $guide->graph();
    $ids = array_column($graph['sources'], 'id');
    $nodes = array_merge(array_map(fn ($step) => 'step:'.$step['id'], $graph['steps']), array_map(fn ($id) => 'source:'.$id, $ids));
    $visited = ['step:outcome'];
    do {
        $before = $visited;
        foreach ($graph['edges'] as $edge) {
            expect($nodes)->toContain($edge['from'], $edge['to']);
            if (in_array($edge['from'], $visited, true)) {
                $visited[] = $edge['to'];
            }
        }
        $visited = array_values(array_unique($visited));
    } while (count($visited) !== count($before));

    expect(array_diff($nodes, $visited))->toBe([]);
    foreach ($graph['steps'] as $step) {
        expect($step['source_ids'])->not->toBeEmpty();
        foreach ($step['source_ids'] as $id) {
            expect($ids)->toContain($id);
        }
    }
    foreach ($ids as $id) {
        $source = $guide->source($id);
        expect($source['url'])->toStartWith('https://')
            ->and($source['revision'])->not->toBeEmpty()
            ->and(hash('sha256', $source['content']))->toBe($source['sha256']);
    }
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/planning', FilesystemIterator::SKIP_DOTS)) as $file) {
        $size += $file->getSize();
    }
    expect($size)->toBeLessThanOrEqual(128 * 1024);
});

it('traverses unanswered questions in order and finishes after all decisions are supplied', function () {
    $guide = app(PlanningGuide::class);
    $answers = [];
    foreach (['outcome', 'state', 'laravel', 'boundaries', 'verification'] as $expected) {
        expect($guide->nextStep($answers)['id'])->toBe($expected);
        $answers[$expected] = 'User decision';
    }
    expect($guide->nextStep($answers))->toBeNull();
});

it('keeps NativePHP mobile and desktop citations separate and finds relevant sources offline', function () {
    $guide = app(PlanningGuide::class);
    $sources = $guide->sourcesFor('NativePHP mobile app with offline records');

    expect(array_column($sources, 'id'))->toContain('nativephp-mobile')
        ->and($guide->source('nativephp-mobile')['url'])->not->toBe($guide->source('nativephp-desktop')['url'])
        ->and($guide->source('nativephp-mobile')['content'])->toContain('Mobile v4')
        ->and($guide->source('nativephp-desktop')['content'])->toContain('Desktop v2');
});

it('rejects a source identifier outside the bundled graph', function () {
    expect(fn () => app(PlanningGuide::class)->source('../../.env'))->toThrow(RuntimeException::class, 'GUIDE_SOURCE_NOT_FOUND');
});

it('keeps citations from completed steps even when the answers contain no matching topics', function () {
    $guide = app(PlanningGuide::class);
    $sources = $guide->sourcesFor('A small improvement.', array_fill_keys(['outcome', 'state', 'laravel', 'boundaries', 'verification'], 'Agreed.'));

    expect($sources)->not->toBeEmpty()
        ->and($sources[0]['id'])->toBe('mary-tarpit')
        ->and(array_column($sources, 'id'))->toContain('laravel-container');
});
