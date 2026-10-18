# Workflow map

Each command below is one capability from the October 17 inventory that had no screen. The owner surface is the place a person uses today. The action is the class the command calls. States are the ones that class, or the model it writes, already uses. A later screen has to call that action and refuse the transitions it refuses.

The readiness page is the exception that now has a screen. [Web interface](web-interface.md#readiness) describes it. The [coverage register](plans/molly-ui-coverage.json) records the other gaps.

## Readiness and setup

| Command | Surface | Action | States the backend already uses |
| --- | --- | --- | --- |
| `molly:doctor` | `/molly/readiness`, and the `molly:doctor` command | `CheckEnvironment::handle()` | Each check is `passed`, `failed`, or `unknown`. `ready` is true when none are `failed`. `unknown` does not fail the run. |
| `molly:preflight` | Command only | `InspectHardware::handle()`, then `DecideModelFit::handle()` | Decision status: `recommended_fit`, `minimum_fit`, `already_installed`, `no_fit`, `memory_unavailable`, `unsupported`, or `unknown`. The command downloads nothing. |
| `molly:install-model` | Command only | `InstallModel::plan()`, then `InstallModel::install()` after approval | `plan()` changes nothing. Without a terminal, install stops with `DOWNLOAD_AUTHORIZATION_REQUIRED` until the same command is run with `--approve`. Exit 0 only when the status is `ready`. |
| `molly:setup` | Command only | `ConfigureAgent::handle()` after the command finds Git, and Amp when the agent is Amp | Result status `configured` or `failed`. Agents are `ollama` and `amp`. |
| `molly:demo` | Command only | `CreateTask::handle()` after the command writes the greeting files | The saved task is `pending`. The command does not call the model. |

`molly:doctor` is not a stored state machine. The page runs the checks during the request. There is no saved `checking` row.

## Orbs

| Command | Surface | Action | States the backend already uses |
| --- | --- | --- | --- |
| `molly:orbs` | Command only | `LocalOrbProvider::all()` | `Orb::availability()` returns `available`, `busy`, `unhealthy`, or `revoked`. `--check` asks each runtime for health and records a heartbeat. |
| `molly:orb-register` | Command only | `LocalOrbProvider::register()` | A new row is registered. Invalid name, runtime, model, repository, or worktree root throws an `ORB_` code and saves nothing. |
| `molly:orb-revoke` | Command only | `LocalOrbProvider::revoke()` | Sets `revoked_at`. The id and history stay. A revoked Orb takes no new task. `molly:worker start --orb=` refuses it with `ORB_REVOKED`. |

The plan's words ready, stale, and unavailable are not Orb availability. `stale` is a worker record state from `ManageWorker` (`running`, `stopped`, or `stale`). An Orb with `health` other than `healthy` is `unhealthy`. Nothing selects a revoked or unhealthy Orb for new work.

## Tasks and the queue

| Command | Surface | Action | States the backend already uses |
| --- | --- | --- | --- |
| `molly:queue` | Task page Start and Retry call `QueueTask`. They do not show `already_queued` or an Orb choice. The command does. | `QueueTask::request()` | Task row: `pending`, `running`, `failed`, `stopped`, `completed`. Start is allowed from `pending`. Retry is allowed from `failed` or `stopped`. A second job for the same task and kind is not added while one is queued or running (`queued` false, command status `already_queued`). |
| `molly:run` | Command only | `RunTask::handle()` | One run, no reusable task and no nickname. The required test stays protected. |
| `molly:chat` | Command only | No action. `MollyChatCommand` starts the Amp process. | `AMP_MISSING` when the binary is absent. `--json` prints the command and does not start Amp. An interactive session needs a TTY. |

`LocalAgentBus::refuseUnclaimable()` also refuses a start or retry that would repeat a completed idempotency key (`COMMAND_ALREADY_SUCCEEDED`), an invalid `molly.max_attempts`, or a used attempt budget (`ATTEMPT_LIMIT_REACHED`). Display status is separate and comes from `LifecycleLog`: `pending`, `preparing`, `running`, `awaiting_approval`, `approved`, `failed`, `stopped`, `handed_off`, `merged`.

The web Start button queues. It is not `molly:start`, which runs in the terminal.

## Verification

| Command | Surface | Action | States the backend already uses |
| --- | --- | --- | --- |
| `molly:check` | None. The parallel runner calls the hidden command with an input file and an output file. | `VerifyChanges::handle()` or `ReviewChanges::handle()`, after the command applies `config` from that file | Not a task status. The command also calls `posix_setsid`. Do not expose the input and output paths to a browser. |
| `molly:lock-test` | Command only. MCP refuses it. | `LockProtectedTest` | Requires `--approve` or the command throws `TEST_LOCK_UNCONFIRMED` and locks nothing. A test that cannot run throws `AUTHORED_TEST_BROKEN` and locks nothing. |
| `molly:review-commit` | Command only | `ReviewCommit::handle()` | A PHP diff review. With Jev on, the evaluation is advice. It does not start or complete a task. |
| `molly:seam` | Command and MCP | `SeamWorkflow::handle()` | Operations: `list`, `inspect`, `compare`, `validate`, `plan`, `preview`, `write`, `advance`, `status`, `report`, `cancel`, `acknowledge`, `resume`. Required step ids, in order: `harness`, `pre`, `lock`, `implementation`, `post`, `sensitivity`, `cleanup`, `handoff`. Run status on the returned run is the run row's status. |
| `molly:verify` | Command only | `VerifyMolly::verify()` | Acceptance outcomes are `PASS`, `FAIL`, `BLOCKED`, and `UNVERIFIED`. `VerificationState` is `PASS`, `FAIL`, `REVIEW_REQUIRED`, or `NOT_RUN`. `REVIEW_REQUIRED` is not `PASS`. |

## GitHub handoff

| Command | Surface | Action | States the backend already uses |
| --- | --- | --- | --- |
| `molly:import` | Create-task form sends `issue_url` to `ImportGitHubIssue`. It does not pass `--todos`. | `ImportGitHubIssue::handle()` | Saves a `pending` task. Writes nothing to GitHub. `--todos` needs `--allow-test-edits` and refuses an existing test or an issue with no acceptance criteria. |
| `molly:story` | Command only | `CreateTaskFromStory` | Saves a test-authoring task and prints the next commands. It does not lock the test. |
| `molly:pr-body` | Command and MCP, which only prints | `ComposePullRequestBody::handle()` | Allowed when display status is `approved` or `merged`. Prints text. Does not open a pull request. |
| `molly:comment` | Command only. MCP refuses it. | `PublishGitHubIssueStatus::handle()` | Requires `--approve` (`GITHUB_WRITEBACK_UNAPPROVED`). The task must have been imported. The same body is not posted twice. |
| `molly:pr-opened` | Command only. MCP refuses it. | `RecordPullRequestOpened::handle()` | Requires `--approve`. Display status must be `approved`. The URL's repository must match the imported repository. The same URL records once. A different URL throws `PR_RECORD_CONFLICT`. Payload `opened` is false. |
| `molly:merged` | Command only. MCP refuses it. | `RecordMerged::handle()` | Requires `--approve`, a recorded pull request, and an approval event. The same SHA records once. Payload `merged` is false. |

## Knowledge

| Command | Surface | Action | States the backend already uses |
| --- | --- | --- | --- |
| `molly:project:index` | Project graph page. Rebuild calls `InspectProjectGraph`, which calls `IndexProjectGraph`. | `IndexProjectGraph::handle()` | Writes the project graph, then the page shows blockers, nodes, and edges. |
| `molly:knowledge:index` | Command only | `IndexLaravelKnowledge::handle()`, `IndexNativePhpKnowledge::handle()`, or `IndexTarpitKnowledge::handle()` | Builds one namespace. A later query refuses `GRAPH_STALE` when the graph was built for another package version. |
| `molly:knowledge:query` | Command only | `QueryKnowledgeGraph::handle()` | Read. Depth 0 to 3, limit 1 to 40. Freshness is on the result. |
| `molly:knowledge:pack` | Command only | `CollectRunKnowledge::handle()` | Read-only preview of the context a run would receive. |
| `molly:graphs-retry` | Command only | `RetryProjectKnowledgeGraphUnit::handle()` | `UNIT_UNKNOWN` lists the valid units and changes nothing. Manifest freshness status is `fresh`, `stale`, `missing`, or `invalid`. |
| `molly:decide` | Command only | `RecordProjectDecision::handle()` | Writes `docs/decisions/YYYY-MM-DD-title.md`. The same title and body on the same day is unchanged. A different body throws `DECISION_EXISTS`. |
| `molly:journal` | The task page shows `journal_status` and the command to run. It does not write the file. | `ExportTaskJournal` and `RefreshProjectJournal` | `written` or `unavailable` on the task. A write failure does not change the run result. |

## Missing service behavior

These are gaps in the services, not missing pages.

- The readiness page cannot prove the database accepts a write. `CheckEnvironment::probeDatabaseWrite()` is the probe, and it inserts a row. The page passes `probeDatabaseWrite: false`. `molly:doctor` and `molly:status` still run the probe and roll it back.
- `HardwareProbe` records macOS memory, Metal, and disk facts as unknown when `uname -s` is not Darwin. `molly:preflight` and `molly:install-model` still run, and the fit decision can come back `unsupported` or `unknown`. There is no second installer for Linux.
- `molly:chat` and interactive `molly:setup --agent=amp` need a TTY and the Amp binary. `ConfigureAgent` only saves the choice. A browser request cannot own that login.
- `QueueTask::handle()` returns the task and drops the `queued` flag. The task page always says execution was requested, including when the job was already queued.
- `RecordPullRequestOpened` and `RecordMerged` do not read GitHub. They check the saved task, the lifecycle log, and the URL or SHA you pass. Recording does not create or merge a pull request. That part of the plan matches the code. A live check that the pull request or SHA exists on the remote does not.
- `molly:check` reads a JSON file, applies that file's `config`, and runs verification or review. A screen that accepted the path would apply caller-supplied config. Do not add that.
- Hosted Orbs are not a service. Local Orbs are.

## What a screen may do

HTTP, Livewire, and MCP dispatch these actions. They do not decide that a run passed. Preview and refresh stay on the read methods: `CheckEnvironment` with the write probe off, `InstallModel::plan()`, `DecideModelFit`, `ComposePullRequestBody`, and the knowledge query and pack commands. Approval flags stay on the Artisan commands. MCP already refuses `approve`, `lock_test`, `pr_opened`, `merged`, `comment`, and `handoff` with `HUMAN_APPROVAL_REQUIRED`.
