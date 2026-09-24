# Contributing

This page is for changing Molly itself. To use Molly in an application, start with [Getting started](getting-started.md).

## Run the checks

```bash
composer install
vendor/bin/pest
vendor/bin/pint --format agent
```

The package suite runs on PHP 8.3, 8.4, and 8.5 in GitHub Actions, along with the lowest and highest dependency sets, a Jev lane on the accepted Laravel AI commit, fresh Laravel 12 and 13 installs, coverage, mutation checks, the documentation build and link check, Composer validation, and a plugin security scan. `bin/molly-release-gates` runs the local subset before a release.

## What the tests fake

Model responses are faked wherever the model is not the thing under test. Task state, file validation, the Pest subprocess, queue behavior, evidence handling, parallel checks, and the sandbox policy run for real. Jev is tested through Molly's own classifier seam in every lane, and against Laravel AI's classification fakes in the Jev lane. A live run with a configured model is recorded separately and is not part of the suite.

## Documentation

When behavior changes, update the guide developers read, the command or configuration reference when a public input changed, and the glossary only for a new term. `AGENTS.md` requires the Unslop rules for prose, UI copy, and commit messages. Do not describe planned behavior as current on the same page without saying so.

The docs are plain Markdown under `docs/`, read on GitHub; there is no site generator. CI checks that every relative link and heading anchor resolves. `bin/molly-docs-walkthrough` regenerates the web interface screenshots from a running application.

## Scope

Recorded live checks cover small tasks through Ollama and Amp, saved tasks, queue-backed web execution, retries, GitHub issue import, Amp connection observation, and Jev advice, plan suggestions, and commit review against TypeSafe. Those checks show that the exercised paths worked in those environments, not that every model or project is supported.

Remote execution on an Orb is planned. The alpha backlog is in [Work packages](work-packages.md).
