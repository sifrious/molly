# Publishing

> For maintainers. Nothing here is needed to use Molly.

The documentation is plain Markdown under `docs/`, read on GitHub. There is no site generator and no build step. `docs/index.html` is a static landing page that links into the Markdown pages; how and where it is hosted is a separate decision from the docs themselves.

## Checks

The Documentation workflow runs `bin/molly-docs-check` on every change to `README.md`, `docs/`, or the checker. It checks two things:

- Every relative link resolves to a file, and every `#anchor` to a heading, using GitHub's heading slug rules.
- Every page and heading in its `REQUIRED` list exists. The list maps each alpha tutorial requirement from MME-5211 to the page that meets it, such as `docs/github-todos.md#import-with-todos`. A missing entry prints `required doc missing:` with the requirement and fails the check.

Run it locally before pushing:

```bash
python3 bin/molly-docs-check
```

It prints `Checked links in N Markdown files and 12 required alpha docs.` and exits `0`, or lists each problem and exits `1`.

`bin/molly-docs-walkthrough` regenerates the web interface screenshots under `docs/v0.1/walkthrough/` from a running application.

## Release gates

Missing required documentation blocks a release. Run the local gates from the package root on the release host:

```bash
bin/molly-release-gates
```

The script stops at the first failure and exits non-zero. In order, it runs:

1. `python3 bin/molly-docs-check`, the documentation check above
2. `composer validate --strict`
3. `composer install --no-interaction --prefer-dist` from the lock file
4. `composer audit --locked`, which needs network access to the advisory database
5. The full package suite, `vendor/bin/pest --colors=never --fail-on-warning --fail-on-risky --fail-on-phpunit-warning`
6. `composer archive` into a temporary directory, failing if `composer.lock` is inside the zip

It ends with `ALL GATES PASSED on` and the PHP version. The documentation check cannot tell whether an example still runs. The tests that keep examples in step with fixtures do that: `tests/Feature/CustomAgentStepTest.php`, `tests/Feature/GitHubPestTodosTest.php`, and `tests/Feature/DocsExamplesTest.php`, all part of step 5.

When a required tutorial is moved or renamed, update `REQUIRED` in `bin/molly-docs-check` in the same commit. Removing an entry drops a release requirement, so it needs the same review as dropping the feature.

## Switching the public install line to v1

The install lines stay on the `^0.2` constraint until all of these are true:

1. The `v1.0.0` tag exists on `main`.
2. Composer resolves `sifrious/molly:^1.0` from the documented source, Packagist or the public repository.
3. Fresh Laravel 12 and 13 applications install `^1.0`, complete the demo flow, and export a Bloom contract.

Then change these together in one commit:

- `InitializeMollyInExistingProject::RELEASE_CONSTRAINT` and the `MOLLY_CONSTRAINT` default in `bin/molly-demo` (a test keeps them equal)
- The install lines in `README.md`, `docs/index.html`, `docs/getting-started.md`, `docs/quickstart-standalone.md`, and `docs/troubleshooting.md`
- The tagged `bin/molly-demo` download URL in `README.md` and `docs/getting-started.md`
- The supported-versions table in `SECURITY.md`
- The repository line, once Packagist lists the package
