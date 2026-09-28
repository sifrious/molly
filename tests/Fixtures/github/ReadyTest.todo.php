<?php

// Acceptance criteria from https://github.com/sifrious/molly-demo/issues/42
// Molly wrote one todo per criterion. Replace each todo with a test that
// asserts the behavior. Pest reports a todo as incomplete, never as passed.

it('criterion 1: GET /ready returns HTTP 200.')->todo();
it('criterion 2: The response body is exactly {"ready":true}.')->todo();
it('criterion 3: A guest\'s request to GET /ready succeeds without signing in.')->todo();
