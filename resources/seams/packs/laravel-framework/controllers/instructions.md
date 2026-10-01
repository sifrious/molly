# Controller behavior through HTTP

Call the application's registered HTTP route through Laravel's HTTP test client. Supply the controller, production path, request and exact expected status and JSON body for each case. Do not register replacement routes in the test. Calling a controller method directly misses middleware and route registration.

Controllers coordinate requests. Resolve reusable actions through Laravel's container. For an orders endpoint, cover the returned order ID, rejected input, forbidden access, an unknown resource and the application's defined failure response. A database-backed contract also needs persistence and cleanup checks. This adapter accepts stateless JSON endpoints. It rejects unsupported fixtures instead of claiming that it checked database writes or authentication.

The good example calls /ready and asserts both status and the exact JSON body. The bad example only compares literals. It passes even when the application is broken.

## Procedure

1. Describe each expected outcome and exact baseline response. A planned route may return 404 before implementation. A 500 response is a harness failure, not useful RED.
2. Preview the generated tests and production scope. Run the harness and PRE steps against the saved baseline.
3. A person locks the tests through Molly's existing approval path. Pack files cannot authorize approval.
4. Implement within the approved production files. POST executes the same contract and protected tests.
5. Apply the declared behavior-specific negative control in an isolated copy. Its named case must fail. Record cleanup and acknowledge the evidence handoff.

Markdown explains the procedure. Only plan.json selects registered steps. Local edits apply to a new plan revision; they never alter an active revision. Ordinary publishing preserves edits. An explicit vendor:publish --force overwrites them.

## Sources and limits

Source guide: https://linear.app/sifirous/issue/MME-6433
Laravel 12 HTTP tests: https://laravel.com/docs/12.x/http-tests
Laravel 13 HTTP tests: https://laravel.com/docs/13.x/http-tests
Pinned router test: https://github.com/laravel/framework/blob/aad74d9d01406901dbb6e359092cf5e261b62b09/tests/Routing/RoutingRouteTest.php#L1739-L1760

PHP HTTP tests do not prove browser behavior or real external integrations. Those checks remain pending until a registered handler executes them.
