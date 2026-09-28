<?php

it('reports that the application is ready', function () {
    $this->get('/ready')->assertOk()->assertExactJson(['ready' => true]);
});
