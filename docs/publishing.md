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

A requirement whose behavior has not shipped is also listed in `OPEN`, with the reason. The check prints each one as `open alpha requirement:` and still exits `0`, so documentation changes are not blocked by missing features. With `--release`, an open requirement fails the check:

```bash
python3 bin/molly-docs-check --release
```

No requirement is open today. Local and Orb execution targeting was the last one: it closed when local Orbs shipped with [Run tasks on two local Orbs](execution-targets.md#run-tasks-on-two-local-orbs), following Mary's release-scope decision of 2026-09-28 that Orb execution is required for the alpha. With an open entry, `--release` exits `1` with `Release blocked: N alpha requirement(s) open.`

`bin/molly-docs-walkthrough` regenerates the web interface screenshots under `docs/v0.1/walkthrough/` from a running application.

## Release gates

Missing required documentation and open alpha requirements block a release. Run the local gates from the package root on the release host:

```bash
bin/molly-release-gates
```

To run only the documentation gate, which needs no network and no Composer:

```bash
bin/molly-release-gates --docs
```

The script stops at the first failure and exits non-zero. In order, it runs:

1. `python3 bin/molly-docs-check --release`, the documentation check above, including open requirements
2. `composer validate --strict`
3. `composer install --no-interaction --prefer-dist` from the lock file
4. `composer audit --locked`, which needs network access to the advisory database
5. The full package suite, `vendor/bin/pest --colors=never --fail-on-warning --fail-on-risky --fail-on-phpunit-warning`
6. `composer archive` into a temporary directory, failing if `composer.lock` is inside the zip

It ends with `ALL GATES PASSED on` and the PHP version, or with `DOCUMENTATION GATE PASSED` under `--docs`. While any requirement is open, both stop at step 1. The documentation check cannot tell whether an example still runs. The tests that keep examples in step with fixtures do that: `tests/Feature/CustomAgentStepTest.php`, `tests/Feature/GitHubPestTodosTest.php`, and `tests/Feature/DocsExamplesTest.php`, all part of step 5. `tests/Feature/DocsCheckTest.php`, also in step 5, checks that `--release` fails exactly when a requirement is open.

When a required tutorial is moved or renamed, update `REQUIRED` in `bin/molly-docs-check` in the same commit. Removing an entry from `REQUIRED` or `OPEN` drops a release requirement, so it needs the same review as dropping the feature. Remove an `OPEN` entry only when the behavior ships with its tutorial, or cite the recorded release-scope decision in the commit that removes it.

## Download size

The Fresh Laravel jobs run "Check the download contents and size" from `.github/workflows/tests.yml`. That step is separate from `bin/molly-release-gates`. The release script only checks that `composer.lock` is absent from `composer archive`.

The job builds `molly.zip` with `git archive` and `molly-composer.zip` with `composer archive`. Both must be at most 704 KiB (720896 bytes). The archives must omit `tests/`, `.github/`, and `.git`. They must include `resources/planning/guide.json`, `resources/planning/LARAVEL-LICENSE.md`, and `src/Complexity/LICENSE.md`. Uncompressed files under `resources/planning/` must total at most 128 KiB.

`git archive` follows `export-ignore` in `.gitattributes`. `composer archive` follows the `archive.exclude` list in `composer.json`. `bloom-plugin/Surfaces.bundle` is already excluded from both. Commit the tree you want `git archive` to measure. `composer archive` reads the worktree.

From the package root, with `git`, `python3`, and `composer` on `PATH`:

```bash
set -euo pipefail
archive_dir="$(mktemp -d)"
trap 'rm -rf "$archive_dir"' EXIT
git archive --format=zip --output="$archive_dir/molly.zip" HEAD
composer archive --format=zip --dir="$archive_dir" --file=molly-composer --no-interaction
RUNNER_TEMP="$archive_dir" python3 - <<'PY'
import os
from pathlib import Path
from zipfile import ZipFile
for filename in ['molly.zip', 'molly-composer.zip']:
    archive = Path(os.environ['RUNNER_TEMP']) / filename
    with ZipFile(archive) as package:
        names = package.namelist()
        assert not any('/tests/' in '/' + name or name.startswith('.github/') for name in names)
        assert not any(name == '.git' or name.startswith('.git/') for name in names)
        assert 'resources/planning/guide.json' in names
        assert 'resources/planning/LARAVEL-LICENSE.md' in names
        assert 'src/Complexity/LICENSE.md' in names
        assert sum(item.file_size for item in package.infolist() if item.filename.startswith('resources/planning/')) <= 128 * 1024
        size = archive.stat().st_size
        print(f'{filename}: {size} bytes')
        assert size <= 704 * 1024
PY
```

A pass prints both sizes and exits 0. A failed `assert` exits 1. `set -e` stops the shell on that failure. The `trap` removes the temporary directory either way.

Shipped PHP under `src/` counts toward the 704 KiB cap. The cap is the literal `704 * 1024` in that workflow step. A path is omitted from both archives when it is `export-ignore` in `.gitattributes` and listed in `composer.json` `archive.exclude`. `.github/` and `docs/` are both `export-ignore`, so this workflow edit and this page do not change the zip.

### Why 640 KiB

`git archive` of this tree is 562353 bytes. `git archive` of `main` `08dee7d` is 384517 bytes. The difference is 177836 bytes. Headroom under 655360 is 93007 bytes.

| Piece | Bytes in the zip |
| --- | ---: |
| Paths in this archive and absent from `main` | 137841 |
| Compressed growth of paths in both archives | 44623 |
| Paths `main` archived and this tree omits | -4628 |

Those three lines reconcile: `384517 - 4628 + 44623 + 137841 = 562353`.

The new paths are runtime code: model fit, Orbs, the worker, redaction, hardware probe, story commands, the model catalogue, and Bloom screen sources. `MollyServiceProvider::registerCommands()` registers those commands. `AcceptanceWriter` is the agent behind `molly:story`.

`main` had 25083 bytes of room under the old 409600 byte cap. Shared files then grew by 44623 compressed bytes. An archive with those shared files and none of the new paths is 424512 bytes, 14912 over 409600. Dropping `bloom-plugin/` (30837 bytes, including sources you build from a checkout) leaves 531516. Also dropping `README.md`, `SECURITY.md`, and `resources/models/catalogue.schema.v1.json` leaves 524889. The schema is documented as part of the shipped catalogue. The planning sources have to stay: the workflow asserts they are present, and `PlanningGuide` reads them.

`export-ignore` cannot bring this tree under 409600 without removing runtime PHP that already shipped on `main`. That raise allows `640 * 1024` bytes.

### Why 704 KiB

Fresh Laravel 12 and 13 at `ab65262` measured `git archive` at 688192 bytes, 32832 over 655360. `main` `aa5e72b` is 645574, 9786 under that cap. The archive grew by 42618 bytes.

| Piece | Compressed bytes |
| --- | ---: |
| New paths (`src/Acceptance/`, `VerifyMolly.php`, `MollyVerifyCommand.php`, `MollyVerify.php`) | 35344 |
| Growth of paths already in `main` (`RunTask.php`, `README.md`, `MollyServer.php`, `MollyServiceProvider.php`) | 1010 |
| Zip directory overhead for those entries | 6264 |

Those three lines reconcile: `645574 + 35344 + 1010 + 6264 = 688192`.

`molly:verify` and `molly_verify` load that PHP. `PermissionPreflight` inspects Screen Recording and Accessibility. `BloomHostInspector` reads a Bloom process that is already running. `RunTask` stores verifier status when a run stops before Pest. The new tests are already `export-ignore`. `docs/` and `.github/` are already `export-ignore`. `bloom-plugin/Surfaces.bundle` is already excluded. Removing the new PHP would drop permission preflight or host inspection from the package.

The check now allows `704 * 1024` bytes (720896). Headroom under that cap, against the 688192 byte archive, is 32704 bytes.

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
