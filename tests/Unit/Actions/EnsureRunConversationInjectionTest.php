<?php

use Sifrious\Molly\Actions\EnsureRunConversation;
use Sifrious\Molly\Conversations\ConversationStore;

it('injects ConversationStore into EnsureRunConversation', function () {
    $action = app(EnsureRunConversation::class);
    $r = new ReflectionClass($action);
    $prop = $r->getProperty('store');
    $prop->setAccessible(true);
    expect($prop->getValue($action))->toBeInstanceOf(ConversationStore::class);
});

it('requires ConversationStore on direct construction', function () {
    $store = app(ConversationStore::class);
    $action = new EnsureRunConversation($store);
    $r = new ReflectionClass($action);
    $prop = $r->getProperty('store');
    $prop->setAccessible(true);
    expect($prop->getValue($action))->toBe($store);
});
