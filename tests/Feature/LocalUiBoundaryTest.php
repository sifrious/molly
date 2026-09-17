<?php

use Sifrious\Molly\Http\LocalUi;

mutates(LocalUi::class);

beforeEach(function () {
    config(['session.driver' => 'array', 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
});

afterEach(function () {
    $this->app->detectEnvironment(fn () => 'testing');
});

it('serves the local interface for supported loopback clients and hosts', function (string $environment, string $address, string $host) {
    config(['molly.ui.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    $this->app->detectEnvironment(fn () => $environment);

    $this->withServerVariables(['REMOTE_ADDR' => $address])
        ->get('http://'.$host.'/molly')->assertOk()->assertSee('Create task');
})->with(['local', 'testing'])->with(['127.0.0.1', '::1'])->with(['localhost', '127.0.0.1', '[::1]']);

it('requires an explicit boolean opt in even on a local connection', function (mixed $enabled) {
    config(['molly.ui.enabled' => $enabled]);

    $this->get('/molly')->assertNotFound();
})->with([false, null, 1, 'true']);

it('hides the interface outside the supported application environments', function (string $environment) {
    config(['molly.ui.enabled' => true]);
    $this->app->detectEnvironment(fn () => $environment);

    $this->get('/molly')->assertNotFound();
})->with(['production', 'staging']);

it('rejects a forged local address or host before rendering task data', function (mixed $address, string $host, array $headers) {
    config(['molly.ui.enabled' => true]);

    $this->withServerVariables(['REMOTE_ADDR' => $address])->withHeaders($headers)
        ->get('http://'.$host.'/molly')->assertForbidden()->assertDontSee('Create task');
})->with([
    'remote with forwarded address' => ['203.0.113.1', 'localhost', ['X-Forwarded-For' => '127.0.0.1']],
    'remote IPv6' => ['2001:db8::1', '[::1]', []],
    'missing client address' => [null, 'localhost', []],
    'invalid client type' => [true, 'localhost', []],
    'untrusted host with forwarded host' => ['127.0.0.1', 'example.test', ['X-Forwarded-Host' => 'localhost']],
    'loopback name suffix' => ['127.0.0.1', 'localhost.example.test', []],
]);
