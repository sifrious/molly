<?php

it('criterion 1: GET /ready returns HTTP 200.', function () {
    $this->get('/ready')->assertOk();
});

it('criterion 2: The response body is exactly {"ready":true}.', function () {
    expect($this->get('/ready')->getContent())->toBe('{"ready":true}');
});

it('criterion 3: A guest\'s request to GET /ready succeeds without signing in.', function () {
    $this->assertGuest();
    $this->get('/ready')->assertOk();
});
