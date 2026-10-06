# Command behavior through Artisan

Call the command registered by the application. Supply its name, arguments, expected output and exit code. Calling handle directly misses registration and argument parsing. Keep the command thin and resolve its application action through Laravel's container.

Cover successful output and the defined nonzero failure response. When a command supports dry-run, add an integration check that proves it leaves application state unchanged. Exit zero alone does not establish the promised behavior.

The good example calls readiness:check and checks its output and exit. The bad example checks a literal without running a command. Neither direct method calls nor fakes establish command-to-command wiring.

## Procedure

1. Record the registered command's baseline output and exit for every case. Missing commands, malformed options and bootstrap exceptions block PRE.
2. Preview the tests. Check that the arguments select the intended behavior, then run the harness and PRE checks against the saved baseline.
3. A person locks the tests through Molly's existing approval path. Pack edits cannot authorize this decision.
4. Change only the approved production files. POST must execute the unchanged tests and contract.
5. Apply the declared negative control in an isolated copy. Its named case must fail for the changed output or exit. Record cleanup and acknowledge the evidence handoff.

This adapter supports noninteractive commands. Database fixtures, interactive prompts, external services and file side effects need registered setup, verification and cleanup handlers. Unsupported checks remain pending.

Local files are editable. Markdown explains the work; plan.json selects registered handlers. An edit requires a new plan revision and invalidates prior evidence. Ordinary publishing preserves edits. --force overwrites published files.

## Sources

Source guide: https://linear.app/sifirous/issue/MME-6444
Laravel 12 console tests: https://laravel.com/docs/12.x/console-tests
Laravel 13 console tests: https://laravel.com/docs/13.x/console-tests
Pinned command test: https://github.com/laravel/framework/blob/aad74d9d01406901dbb6e359092cf5e261b62b09/tests/Console/CommandTest.php#L34-L67
