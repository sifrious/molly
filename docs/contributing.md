# Contributing

This page is for changing Molly itself. To use Molly in an application, start with [Getting started](getting-started.md).

## Run the checks

```bash
composer install
```

`composer install` and `composer update` set `git config core.hooksPath .githooks`. Run that command yourself if you installed with `--no-scripts`. The hook in `.githooks/pre-commit` then runs on every commit:

- `composer validate --strict`
- `vendor/bin/pint --test --dirty --format agent`
- `python3 bin/molly-docs-check`
- Pest on staged files under `tests/`. The flags match the old package-test job: `--fail-on-warning`, `--fail-on-risky`, and `--fail-on-phpunit-warning`. A commit that stages PHP under `src/` and no test file still finishes the hook, and it tells you to run `composer test` before you push. The full suite is too slow to run on every commit.

The same suite, without waiting for a commit:

```bash
composer test
vendor/bin/pint --format agent
```

`bin/molly-release-gates` is the check to run before a release. It adds `bin/molly-docs-check --release`, `composer audit --locked`, and a `composer archive` check that `composer.lock` is not in the zip.

These used to run on GitHub Actions. A push does not run them. Run them on the machine you release from:

- Lowest and highest dependency sets. The lowest lane must resolve `laravel/ai` 1.0.0. `composer update --prefer-lowest --prefer-stable` on PHP 8.3, then the package suite. `composer update --prefer-stable` on PHP 8.5, then the package suite.
- Fresh Laravel 12 and 13 installs, and the archive size check in [Publishing](publishing.md#download-size).
- Coverage: `vendor/bin/pest --colors=never --coverage-text`
- Local UI mutation, which needs Xdebug: `vendor/bin/pest tests/Feature/LocalUiBoundaryTest.php --mutate --class='Sifrious\Molly\Http\LocalUi' --min=100 --no-cache --colors=never`
- HOL Guard plugin scan (minimum score 80, fail on high). This repository has no local scanner for that check.

Dependabot still opens Composer update pull requests. It no longer opens GitHub Actions bumps, because the workflows are gone. Those pull requests are not tested on GitHub.

## What the tests fake

Model responses are faked wherever the model is not the thing under test. Task state, file validation, the Pest subprocess, queue behavior, evidence handling, parallel checks, and the sandbox policy run for real. Jev is tested through Molly's own classifier seam and against Laravel AI's classification fakes in every lane. A live run with a configured model is recorded separately and is not part of the suite.

## Graph snapshots

`tests/Fixtures/graphs` holds a golden snapshot of each graph Molly builds: the project graph for a fixture project, each Laravel guide graph pinned to Laravel 13, the NativePHP desktop and mobile graphs, the Tarpit notes graph, and the glossary terms with their provenance and links. Each file puts every source, node, and edge on its own line, named by type and key, with its endpoints and provenance, so a changed relationship shows up as a one-line diff. The Laravel files leave out a reflected file's digest, line numbers, and package version, because those come from the installed `laravel/framework` release.

The tests never write these files on their own. After an intended graph change, rewrite them and review the diff before you commit:

```bash
MOLLY_UPDATE_GRAPH_GOLDENS=1 vendor/bin/pest --group=graph-golden
git diff tests/Fixtures/graphs
```

The update run marks each rewritten snapshot test incomplete, so it never counts as a passing run.

## Documentation

When behavior changes, update the guide developers read, the command or configuration reference when a public input changed, and the glossary only for a new term. `AGENTS.md` requires the Unslop rules for prose, UI copy, and commit messages. Do not describe planned behavior as current on the same page without saying so.

The docs are plain Markdown under `docs/`, read on GitHub; there is no site generator. The pre-commit hook checks that every relative link and heading anchor resolves. `bin/molly-docs-walkthrough` regenerates the web interface screenshots from a running application.

## Scope

Recorded live checks cover small tasks through Ollama and Amp, saved tasks, queue-backed web execution, retries, GitHub issue import, Amp connection observation, and Jev advice, plan suggestions, and commit review against TypeSafe. Those checks show that the exercised paths worked in those environments, not that every model or project is supported.

Local Orbs ship; hosted Orbs on another machine are a planned follow-up, described in [Hosted Orbs](execution-targets.md#hosted-orbs). The historical alpha backlog is in [Work packages](work-packages.md).
