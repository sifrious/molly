# UI coverage on 18 October 2026

Main and the October 17 inventory are the same commit: `5ac38998afe5ba37c595913ac8302e20e6e42ec2`. The worktree was clean. Nothing in the 29 missing-UI rows or the planned rows landed after that commit, because there is no later commit on `main`.

The machine-readable register is [molly-ui-coverage.json](molly-ui-coverage.json). This page is the short reading of it. The [workflow map](../workflows.md) names the action and the states for each missing command.

## How many screens exist

Of the 29 commands the inventory listed as missing a UI:

- 2 have a screen that runs the capability's action. The project graph page rebuilds through `InspectProjectGraph` and `IndexProjectGraph`. The readiness page runs `CheckEnvironment` for `molly:doctor`, without the database write probe.
- 2 have a partial entry. The create-task form imports a GitHub issue and does not write Pest todos. The task page Start and Retry buttons call `QueueTask` and do not show `already_queued` or an Orb choice.
- 25 have no screen.

The inventory's "30 planned" count does not match the parent ids in its own list. That list names 28 ids under M08 and three under M09 (`MME-5885`, `MME-6214`, `MME-5341`), which is 31. The register records those 31 and keeps the parenthetical children on their parent. Which single id the inventory meant to fold in is unknown.

None of those 31 parents gained a new screen from a commit after `5ac3899`. A few already have code or a page on this commit. The register says which, and it does not treat an id mentioned only in the acceptance matrix as shipped.

## Questions

Q01. No missing-UI row and no planned row landed after `5ac3899`. The graph page, the import field, and the queue buttons were already on that commit.

Q02. `molly:chat`, and `molly:setup --agent=amp` without `--no-login`, need a TTY and the Amp binary. `molly:preflight` and `molly:install-model` run on other operating systems, and `HardwareProbe` leaves the macOS facts unknown there. They do not need the Bloom process. `molly:start` runs in PHP. Queued work needs a queue worker. Local Orbs are local worker processes. Hosted Orbs are not shipped. Whether a future native host must own model installation is unknown. The current installer is Artisan plus the local Ollama API.

Q03. Human approval is `--approve` on `molly:approve`, `molly:lock-test`, `molly:pr-opened`, `molly:merged`, `molly:comment`, and `molly:handoff`. MCP refuses those operations with `HUMAN_APPROVAL_REQUIRED` and writes nothing. `molly:install-model` requires a terminal confirmation or `--approve`. `QueueTask` will not add a second start or retry job for a task that already has one queued or running. `LocalAgentBus::refuseUnclaimable()` blocks a start from any status other than `pending`, a retry from any status other than `failed` or `stopped`, and a repeat of a completed idempotency key.

Q04. Open pull requests on 18 October 2026 are dependabot updates only: 121, 126, 127, 128, 129, 132, 133, and 134. None of them is a doctor, Orb, verification, GitHub, or knowledge screen. Remote branch tips that are not in `main`, and are not open pull requests, include `cursor/learning-paths-mme-6236-3d92`, `cursor/graph-delta-view-3d4a`, `cursor/host-observation-recorders-8d65`, `cursor/mme-7052-permission-preflight-c122`, `codex/molly-jev-integration`, and `cursor/readme-first-run-f970`. Main already has learning-path routes and a change-impact page. Whether those two branches add anything beyond that was not diffed. `mme-5237-jit-context-pack` is contained in `main`.

Q05. Unknown. `bin/molly-release-gates` does not name these planned ids. `docs/publishing.md` says no alpha tutorial requirement is open. `docs/acceptance/matrix.md` says human review (`MME-6214`) waits on a machine pass and that Packagist publication (`MME-5341`) has not happened. `docs/work-packages.md` defers Bud (`MME-5145`) and Penelope (`MME-5144`). That is not a list of what the next tag requires.

## Readiness page

`/molly/readiness` is the M02.1 screen. The navigation link opens it for the application directory. A task page link opens it for that task's workspace. Refresh is a GET and reruns the checks. The database write probe stays on `molly:doctor` because that probe inserts a row, even though the command rolls the row back.
