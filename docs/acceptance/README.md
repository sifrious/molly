# Acceptance

> For maintainers running MME-5885 machine acceptance. Nothing here is needed to use Molly.

Acceptance checks one immutable candidate against the frozen [`manifest.json`](manifest.json). Three steps produce a verdict: `bin/molly-candidate` builds the candidate, each check writes an evidence record, and `bin/molly-acceptance-gate` compares every record to the manifest and the candidate.

The gate only reads evidence. The gate does not run checks, so a record is only as good as the command that wrote it.

## Build a candidate

Build from a clean working tree. The builder refuses uncommitted changes and any commit git cannot resolve:

```bash
bin/molly-candidate bef10d0 0.2.0-RC1 --out ~/molly-acceptance/0.2.0-RC1
```

The output directory then holds two files:

- `sifrious-molly-<sha8>.zip`: `git archive` of the commit, so paths marked `export-ignore` in `.gitattributes` (tests, docs, `composer.lock`) are left out. The builder writes `"version"` and `extra.molly-candidate.commit` into `composer.json` so Composer can install the zip from an `artifact` repository.
- `candidate.json`: `commit`, `version`, `artifact`, `sha256`, `built_at`, and `builder` (script, host, PHP, zip, and git versions).

Building the same commit and version twice gives the same `sha256`. The builder sorts zip entries, sets every mtime to 1980-01-01 UTC, sets modes to 644 or 755, and drops zip extra fields (`zip -X -D`). `built_at` changes on every build and lives only in `candidate.json`, not in the zip.

Pass `--repo <dir>` to build from another checkout of the repository.

## Install the candidate

Point a consumer application at the output directory as a Composer artifact repository:

```bash
composer config repositories.molly-candidate artifact ~/molly-acceptance/0.2.0-RC1
composer require sifrious/molly:0.2.0-RC1
```

## Record evidence

Put evidence in one directory with an `index.json` at its root. Store each file under the subcase id, using the evidence name from the manifest. `M02.1` lists `install-l12.log, install-l13.log`, so both files go in `M02.1/`. A name ending in `/`, such as `M10.4`'s `molly-ui/`, is a directory and needs at least one file inside it:

```
evidence/
  index.json
  M02.1/install-l12.log
  M02.1/install-l13.log
  M10.4/molly-ui/dashboard.png
```

`index.json` holds one record per subcase. [`evidence-schema.json`](evidence-schema.json) defines the fields:

```json
{
  "schema": "molly.acceptance-evidence/1",
  "records": [
    {
      "subcase": "M06.6",
      "outcome": "PASS",
      "mode": "both",
      "candidate_sha": "bef10d0cce9bd39f51b94d719518c280dbbe0143",
      "artifact_sha256": "<candidate.json sha256>",
      "lock_sha256": "<shasum -a 256 composer.lock in the consumer>",
      "timestamp": "2026-09-28T14:02:11Z",
      "command": "vendor/bin/pest --log-junit green-junit.xml",
      "exit_code": 0,
      "counts": { "discovered": 12, "passed": 12, "failed": 0, "skipped": 0 },
      "files": [{ "path": "M06.6/green-junit.xml", "sha256": "<shasum -a 256>" }],
      "intervention": null,
      "protected_digest_expected": "<digest from lock.json>",
      "protected_digest_observed": "<digest after the run>"
    }
  ]
}
```

Set `counts` to `null` only when the check ran no tests. Set `intervention` to a sentence when a person had to step in; the gate reports it but does not reject it. Write `timestamp` after the check finishes, and compute every `sha256` after the file is final.

## Declare skipped tests

A record may skip a test only when the manifest declared that skip before the run. Each key in `manifest.not_applicable` names the subcases it applies to in `applies_to` and the tests it excuses in `tests`, written as `tests/Feature/SandboxIsolationTest.php: lets the writer change only the allowed file`. A key whose skip is allowed only on some hosts also carries a `when` condition:

| Condition | Holds when |
| --- | --- |
| `os_family` | The run's `os_family` is in the list, such as `["Darwin"]`. |
| `php_below` | The run's `php` version is lower than the value, such as `"8.4"`. |

The gate reads each `.xml` file a record lists as a junit report when its root element is `testsuites` or `testsuite`. Every skipped test case must match a test named by a key that the record lists in `skip_reasons`, that the manifest declares for the subcase, and whose condition holds for that junit file. Give each junit file its environment in `test_runs`, using PHP's `PHP_OS_FAMILY` value and the PHP version the suite ran on:

```json
"skip_reasons": ["landlock-linux-only", "laravel-ai-pre-v1-fallback", "sqlite-competing-workers-php84"],
"test_runs": [
  { "junit": "M10.3/junit-macos.xml", "os_family": "Darwin", "php": "8.4.23" },
  { "junit": "M10.3/linux-8.3/junit.xml", "os_family": "Linux", "php": "8.3.35" }
]
```

A junit file without a `test_runs` entry has no environment, so only a key without a `when` condition can excuse its skips. A declared test matches the junit test case with the same file and name, the same name with Pest's `it ` prefix, and each dataset row of that test.

When `counts.skipped` is above 0, it must equal the number of skipped test cases in the record's junit files. A record whose counts tally checks rather than tests, such as a negative control that keeps the junit files of its suite runs, keeps `counts.skipped` at 0. The gate still checks each skipped test case in those junit files.

## Run the gate

```bash
bin/molly-acceptance-gate \
  --manifest docs/acceptance/manifest.json \
  --evidence ~/molly-acceptance/evidence \
  --candidate ~/molly-acceptance/0.2.0-RC1/candidate.json
```

The gate prints one row per criterion with accepted and mandatory subcase counts, then one line per rejection. Add `--json` to print the JSON report instead, or `--report gate.json` to also write the report to a file. The gate needs PHP 8.1 or later and no Composer packages, so it runs on a machine that holds only the candidate, the manifest, and the evidence. It reads junit files with PHP's `dom` extension, which PHP enables by default.

| Exit | Meaning |
| --- | --- |
| 0 | Every mandatory subcase passed or carries a declared N/A. |
| 1 | At least one subcase or the candidate was rejected. |
| 2 | The manifest, candidate, or `index.json` could not be read. |

A criterion passes only when all of its mandatory subcases pass.

## Reason codes

Each rejected subcase lists every code that applies.

| Code | Rejected when |
| --- | --- |
| `MISSING_RECORD` | The subcase has no record. |
| `DUPLICATE_RECORD` | The subcase has more than one record. |
| `MALFORMED_RECORD` | A required field is missing or has the wrong type, a listed `.xml` file is not well-formed, or `test_runs` names a file the record does not list. |
| `INVALID_OUTCOME` | `outcome` is not `PASS`, `FAIL`, `BLOCKED`, or `UNVERIFIED`. |
| `NOT_PASSED` | `outcome` is valid but not `PASS`, and no N/A is declared. |
| `MODE_MISMATCH` | `mode` differs from the manifest. |
| `CANDIDATE_SHA_MISMATCH` | `candidate_sha` differs from `candidate.json` `commit`. |
| `ARTIFACT_SHA_MISMATCH` | `artifact_sha256` differs from `candidate.json` `sha256`. |
| `FILE_MISSING` | A listed file is absent, or a manifest evidence name is not listed. |
| `CHECKSUM_MISMATCH` | A file's sha256 differs from the record. |
| `UNSAFE_PATH` | A file path is absolute, uses `..`, or resolves outside the evidence directory. |
| `ZERO_TESTS` | `counts.discovered` is 0. |
| `COUNTS_INCONSISTENT` | `passed + failed + skipped` differs from `discovered`. |
| `FAILED_BUT_PASS` | `counts.failed` is above 0 and `outcome` is `PASS`. |
| `UNDECLARED_SKIP` | A skipped junit test case is not named by a `skip_reasons` key that `manifest.not_applicable` declares for this subcase and whose `when` condition holds for that junit file. Also rejected: `skip_reasons` names a key not declared for this subcase, or `counts.skipped` is higher than the number of skipped test cases in the record's junit files. |
| `SKIP_COUNT_MISMATCH` | `counts.skipped` is above 0 but lower than the number of skipped test cases in the record's junit files. |
| `UNDECLARED_NOT_APPLICABLE` | `not_applicable` names a key that `manifest.not_applicable` does not declare for this subcase. |
| `STALE_EVIDENCE` | `timestamp` is earlier than `candidate.json` `built_at`. |
| `TIMED_OUT` | `timed_out` is true, or `duration_s` exceeds the manifest `timeout_s`. |
| `EXIT_CODE_MISMATCH` | `exit_code` differs from the manifest subcase's `expected_exit_code` (default 0), or the record claims a different expected exit. |
| `PROTECTED_DIGEST_CHANGED` | `protected_digest_observed` differs from `protected_digest_expected`. |

Gate-level errors reject the whole run:

| Code | Rejected when |
| --- | --- |
| `CANDIDATE_ARTIFACT_MISSING` | The zip named in `candidate.json` is not next to it. |
| `CANDIDATE_ARTIFACT_MISMATCH` | The zip's sha256 differs from `candidate.json`. |
| `MALFORMED_INDEX` | `index.json` has the wrong `schema` or no `records` list. |
| `UNKNOWN_SUBCASE` | A record names a subcase the manifest does not have. |

## Verifier permissions

`php artisan molly:verify` is the M04 check runner. The gate still only reads evidence, and this command does not replace it or run the other stages.

Screen Recording and Accessibility belong to the process that drives the Bloom UI. When either one is missing, a check that still needs that observation is `BLOCKED_VERIFIER_PERMISSION`. The M04 summary counts product failures, harness failures, and permission blocks separately, so those checks are not 16 Molly failures. `release_complete` stays false. A gate record for that check uses `BLOCKED`, not `FAIL`. `FAIL` is only `PRODUCT_FAIL`.

The command asks macOS to show its normal permission dialog and can open the Screen Recording or Accessibility pane. It does not write the TCC database, and `permission_granted_by_cli` is true only when a later inspection reports the permission granted. `--permissionless` skips the request, skips Settings, and does not drive the Bloom UI. Programmatic comparisons still run. `release_complete` stays false while a mandatory native observation is missing. `--retry-native-ui` reruns the permission-blocked checks after you change the permission, and it refuses the saved evidence when the candidate SHA or the verifier environment differs. A failed retry inspection leaves the previous check files unchanged.

On a machine that is not a Mac, both permissions are `unsupported`. That is a property of the verifier environment. It is not a Molly product failure, and it is not a grant. On a Mac, Molly reports the state it observed, so a Screen Recording or Accessibility grant stays `granted`. Checks that do not need the permission still run.

## Limitations

- A record with `counts: null` skips the test-count checks unless the manifest marks the subcase `test_bearing: true`, in which case counts are required.
- `lock_sha256` and `intervention` are recorded and reported but not compared against anything.
- The gate takes each junit file's `os_family` and `php` from `test_runs`. The junit format does not record them, so the gate cannot compare them with the run itself.

## Tests

`tests/Acceptance/AcceptanceGateTest.php` builds a complete, passing evidence set for every subcase in the frozen manifest, then breaks one thing per test and checks that the gate rejects it with the expected code. Each negative control restores the evidence and checks that the gate accepts it again. `tests/Acceptance/CandidateBuildTest.php` builds a throwaway repository twice, once under a different umask, and compares the sha256 values.

```bash
vendor/bin/pest tests/Acceptance
```
