# QuickStart: Molly with Bloom

Bloom is optional. Finish the [standalone QuickStart](quickstart-standalone.md) first; Molly must be installed and passing `molly:doctor` before Bloom adds anything.

## Install the plugin

Plugin 0.2.0 is built against Bloom commit `1599f05f`. From the Molly repository, with a Bloom checkout at that commit:

```bash
export BLOOM_ROOT=/path/to/bloom
cd "$BLOOM_ROOT" && swift build -c release --target BloomPluginAPI
cd -
./bloom-plugin/Surfaces/build-bundle.sh
./bin/molly-bloom-plugin-register
```

Quit and reopen Bloom. The sidebar shows Molly, Tasks, Runs, Conversations, Graph, Glossary, Molly Settings, and Worker.

## Pick a project

Open the Molly screen. Pick a project from `~/.molly/projects.json`, or choose Add Existing to run `molly:project-init` on a Laravel app. Every other screen runs its Artisan commands in that app. If PHP is not in Herd or Homebrew's usual place, set `MOLLY_PHP_BINARY` before launching Bloom.

## Run a task

On the Tasks screen, choose New Task, describe the change, and list the files Molly may change. Open the task and choose Start. The run page shows Pest, every Tarpit check, the Clever probes, and links to the conversation, the workspace diff, and the verification receipts. After you read the change, choose Approve and confirm.

## Bind a Bloom workspace

With a task saved and the project open in Bloom:

```bash
php artisan molly:bloom-contract demo-greeting \
  --workspace-id=WORKSPACE_UUID \
  --branch=BRANCH \
  --base-sha=MERGE_BASE_SHA
```

Bloom binds `.molly/bloom-contract.json` to the workspace, shows the task, and unlocks its pull request controls after the approval. Molly does not open or merge pull requests; `molly:pr-opened` and `molly:merged` record what a person did.

[Molly with Bloom](bloom.md) lists every screen, the commands behind it, and the current limits.
