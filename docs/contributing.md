---
layout: default
title: Contributing
---

# Contributing

Use this page if you are changing Molly itself. Application developers installing Molly should start with [Getting started](getting-started.md).

## Run the package checks

```bash
composer install
vendor/bin/pest
vendor/bin/pint --format agent
```

The main GitHub Actions test workflow runs the package on PHP 8.3, 8.4, and 8.5.

CI also checks Composer metadata, documentation, package archives, mutation behavior, and a clean Laravel installation.

## What tests may fake

The package test suite uses fakes for model responses where the model itself is not the behavior under test.

Execution tests still exercise Molly's task state, file validation, Pest subprocesses, queue behavior, evidence handling, and parallel checks.

A live agent acceptance test is separate from the package suite because it needs a configured Amp account or local Ollama model.

## Keep docs in the same change

When behavior changes, update:

1. the task-focused guide developers will read
2. the command or configuration reference when public inputs changed
3. the glossary only when a new term is truly needed

Use plain, literal language. Molly's `AGENTS.md` requires the unslop rules for documentation, UI copy, and commit messages.

Do not mix current behavior with planned behavior on the same page without a clear label.

## Current verification scope

Recorded live checks cover small local tasks through Ollama and Amp, saved tasks, queue-backed web execution, retries, read-only GitHub issue import, and Amp connection observation.

Those checks show that the exercised paths worked in those environments. They are not a claim that every model, project, or future feature is supported.

Remote Orb identity and remote execution selection remain planned. The alpha backlog lives in [Work packages](work-packages.md).

## Documentation site

Documentation source lives in `docs/`. Maintainers can read [Publishing](publishing.md) for the site rollout plan.
