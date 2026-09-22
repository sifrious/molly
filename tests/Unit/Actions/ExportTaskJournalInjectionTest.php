<?php

use Sifrious\Molly\Actions\ExportTaskJournal;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Actions\ShowTask;
use Sifrious\Molly\Journal\JournalRenderer;
use Sifrious\Molly\Journal\JournalWriter;

it('injects journal collaborators into ExportTaskJournal without service location', function () {
    $action = app(ExportTaskJournal::class);
    $reflection = new ReflectionClass($action);

    foreach ([
        'showTask' => ShowTask::class,
        'lifecycleEvents' => RecordLifecycleEvent::class,
        'journalRenderer' => JournalRenderer::class,
        'journalWriter' => JournalWriter::class,
    ] as $property => $class) {
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        expect($prop->getValue($action))->toBeInstanceOf($class);
    }
});

it('delegates Markdown rendering and glossary copy to JournalRenderer', function () {
    $action = app(ExportTaskJournal::class);
    $reflection = new ReflectionClass($action);
    foreach (['render', 'attempt', 'verification', 'receipts', 'review', 'measurements', 'escape', 'quote', 'glossary'] as $gone) {
        expect($reflection->hasMethod($gone))->toBeFalse();
    }
    expect($reflection->hasMethod('taskPayload'))->toBeTrue()
        ->and($reflection->hasMethod('projectEntryPayload'))->toBeTrue()
        ->and($reflection->hasMethod('runPayload'))->toBeTrue();
});
