---
layout: default
title: Contributing and verification
---

# Contributing and verification

## Run the package tests

```bash
composer install
vendor/bin/pest
vendor/bin/pint --format agent
```

`.github/workflows/tests.yml` runs Composer validation and the package tests on Ubuntu with PHP 8.3, 8.4, and 8.5. Each job resolves dependencies for its PHP version because the package does not commit `composer.lock`. CI installs DOM, SQLite, PCNTL, and POSIX extensions for the verification and process tests. The suite uses model and process fakes where needed and does not require Ollama or an externally managed queue worker. `tests/Feature/QueuedExecutionTest.php` starts isolated database workers with file-backed SQLite, real Pest subprocesses, and the parallel check engine. Only model responses are faked in those integration cases. The competing SQLite workers case skips on PHP 8.3 because Laravel does not apply `transaction_mode` on that version. The single-worker failure and stop cases still run.

The package tests use Pest and Orchestra Testbench. Laravel AI fakes test model responses without requiring Ollama. A live demo requires an installed local model and a host application. Molly supplies the complexity commands.

The first live check used PHP 8.4.23, Laravel 13.32.0, Laravel AI 0.11.2, and the local `gpt-oss:120b-code` model. Molly updated a named health route and its Pest test, then completed the workflow in 25 seconds. Pest passed one test with three assertions. The review returned all seven Tarpit checks without findings, and all four Clever probes returned results before and after the edit. This verifies a small CLI task. It does not establish reliability across larger tasks or other models.

The saved-task workflow also passed a live run after removing the separate Clever package. Molly created and started a task, saved its completed run, and verified a readiness endpoint with one Pest test and three assertions. A real read-only GitHub import preserved issue identity and created a pending task. That import check did not execute the issue.

The local web workflow passed a live create, queue, and retry check with the same model. The first attempt added a version endpoint but failed Pest because the generated test omitted an import. Molly kept the run failed despite a passing Tarpit review. The retry received the recorded test error, added the import, and completed in 30 seconds with one test and two assertions. Pest and review ran in separate overlapping processes. Both attempts remain in task history. Running reports also save the current execution phase before model calls and check transitions.

## Keep documentation current

Update the affected guide and reference when behavior changes. Use repository-relative implementation paths, document configuration defaults without secret values, and check the [glossary](reference/glossary.md) before introducing a term. Apply the unslop rules and the practical tone of Laravel documentation to prose, app labels, and commit messages.

Documentation source lives in `docs/`. The [publishing plan](publishing.md) describes the proposed GitHub Pages site and custom domain.

## Release status

The live checks above cover small tasks in a local application. The [documentation overview](index.md#current-release-scope) lists the capabilities still outside this build. Do not turn a successful demo run into a claim that the original alpha checklist is complete.
