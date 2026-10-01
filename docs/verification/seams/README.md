# Seam workflow verification

This records the first implementation under MME-6576. The executable packs are controllers and commands. The other 153 mapped seams retain source guidance and remain `pending_adapter` with `NOT_RUN` verification. This is not completion of the full epic.

## Executed checks

| Check | Result |
| --- | --- |
| `vendor/bin/pest --compact --colors=never` | Exit 0. 1,968 passed, 5 skipped, 13,249 assertions. Full output in `docs/verification/seams/pest.txt`. |
| Affected existing migration, transport and task tests | Exit 0. 100 passed, 1,124 assertions. Output in `docs/verification/seams/regressions.txt`. |
| `vendor/bin/pint --test --dirty --format agent` | Exit 0, passed. |
| `composer validate --strict` | Exit 0, valid. |
| Composer archive installed in a fresh Laravel app | Publishing, inspection, preserved local edits and three-way comparison passed. Both complete runtime packs were present while `/docs` was excluded. |
| Two live model clients | Claude Code and Codex CLI inspected the same revision and test hash. Scope and identities are in `docs/verification/seams/clients.json`. Execution through those clients remains pending. |

The fresh consumer resolved Laravel Framework v13.34.0, Laravel AI v1.0.1, Laravel MCP v1.0.1, Opis JSON Schema 2.6.0 and Composer Semver 3.5.0. It installed a Composer archive through a local path repository with symlinks disabled. It did not depend on the development checkout's tests or documentation. An initial attempt caught the missing runtime Semver dependency; the final archive includes it.

`tests/Feature/SeamExecutionTest.php` runs both HTTP and registered Artisan cases through real Pest. It covers meaningful RED, refusal of an unapproved lock, human lock, GREEN, targeted negative control, cleanup and acknowledged completion. It also checks explicit already-implemented intake and rejects a different baseline response. `tests/Feature/SeamRecoveryTest.php` covers retries, cancellation, interrupted workers, cursor recovery, changed instructions, changed tests and artifact tampering. `tests/Feature/SeamTransportsTest.php` compares actual CLI/MCP responses and refuses forged success, premature POST and mutation through the read-only tool.

The first full suite exposed the new migration's interruption recovery and the console JSON allowlist. Both were fixed. Child Pest fixtures also inherited the developer's Testbench environment, causing unrelated database/session failures. The fixture now selects array sessions and an in-memory SQLite database. The final full suite passed. Its five skipped tests are not passing evidence.

## Remaining acceptance

- Build and execute the other 153 adapters, with each guide's specific fixtures, boundary cases and failure guidance. The catalogue is research and traceability, not 155 implemented generators.
- Extend the initial contracts beyond stateless JSON and non-interactive command cases. Database fixtures, authentication and native/browser checks remain unsupported by these adapters.
- Complete end-to-end model-coordinated execution with both clients. The live checks exercised planning and reading, while executable verification used the local Pest fixtures.
- Run the platform-dependent checks on supported workers and verify the fresh-consumer flow on Laravel 12 as well as Laravel 13.
- Verify process-kill cleanup during a targeted negative control. Cooperative cancellation and interrupted-step recovery are covered; an OS kill can leave the private temporary copy for operator cleanup.

See `docs/seams.md` for the complete published-instructions example, operations, editable paths, configuration, error catalogue and limits.
