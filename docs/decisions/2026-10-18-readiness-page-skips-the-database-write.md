# Readiness page skips the database write

Date: 2026-10-18
Status: accepted

The local readiness page calls `CheckEnvironment::handle()` with `probeDatabaseWrite` set to false. `molly:doctor` and `molly:status` still insert one `molly_plans` row and roll it back. The page reports Run history as Unknown, code `database_write_not_probed`, and tells you to run `php artisan molly:doctor` for that probe. Refreshing the page does not install a model or start a task.

This record is project memory. It does not start an agent, change verifier policy, or open a pull request.
