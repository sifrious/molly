<?php

use Sifrious\Molly\Actions\EnsureRunConversation;
use Sifrious\Molly\Actions\InspectRun;
use Sifrious\Molly\Actions\InspectTask;
use Sifrious\Molly\Actions\InspectTaskChain;
use Sifrious\Molly\Actions\ShowTask;
use Sifrious\Molly\Conversations\ConversationStore;

it('injects ConversationStore, EnsureRunConversation, ShowTask, and InspectTask into InspectTaskChain', function () {
    $action = app(InspectTaskChain::class);

    expect($action)->toBeInstanceOf(InspectTaskChain::class);

    $reflection = new ReflectionClass($action);

    foreach ([
        'conversations' => ConversationStore::class,
        'ensureConversation' => EnsureRunConversation::class,
        'show' => ShowTask::class,
        'inspect' => InspectTask::class,
    ] as $property => $type) {
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        expect($prop->getValue($action))->toBeInstanceOf($type);
    }
});

it('does not default-construct EnsureRunConversation on InspectTaskChain', function () {
    $ctor = (new ReflectionClass(InspectTaskChain::class))->getConstructor();
    expect($ctor)->not->toBeNull();

    foreach ($ctor->getParameters() as $parameter) {
        expect($parameter->isDefaultValueAvailable())->toBeFalse(
            $parameter->getName().' must be required via constructor injection'
        );
    }
});

it('injects ConversationStore and EnsureRunConversation into InspectRun', function () {
    $action = app(InspectRun::class);

    expect($action)->toBeInstanceOf(InspectRun::class);

    $reflection = new ReflectionClass($action);
    foreach ([
        'conversations' => ConversationStore::class,
        'ensureConversation' => EnsureRunConversation::class,
    ] as $property => $type) {
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        expect($prop->getValue($action))->toBeInstanceOf($type);
    }
});

it('does not default-construct EnsureRunConversation on InspectRun', function () {
    $ctor = (new ReflectionClass(InspectRun::class))->getConstructor();
    expect($ctor)->not->toBeNull();

    foreach ($ctor->getParameters() as $parameter) {
        expect($parameter->isDefaultValueAvailable())->toBeFalse(
            $parameter->getName().' must be required via constructor injection'
        );
    }
});
