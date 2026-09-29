# Fresh-session prompts

Status: draft. Placeholders in angle brackets are filled only with verified values from the accepted candidate. These prompts are not handed to a fresh session as acceptance until every placeholder holds a verified value.

Every Molly command that takes `--approve` records a human decision. The fresh session never runs one. It stops, shows the person the exact command and what it approves, and waits for the person to run it. On macOS, a person also adds `MOLLY_SANDBOX_ALLOW_UNSAFE=true` to the application's `.env` before the first run; the session does not set it. The approved models are the ones in the candidate's catalogue, chosen by `molly:preflight` for the machine.

## Standalone CLI

```text
Install Molly candidate <version> (zip <artifact>, sha256 <sha256>, commit <commit>) into a fresh Laravel <12|13> application without Bloom.

Read README.md and docs/quickstart-standalone.md from the candidate. Before any model download, run `php artisan molly:preflight --json` and explain the decision: the runtime, the model it selected and why, or a truthful no-fit or unknown result. If the selected model is not installed, show the person the `php artisan molly:install-model MODEL` plan and let the person approve the download. Then follow the QuickStart setup commands exactly.

Create or add a disposable project. Submit this story with `php artisan molly:story`:

"Build a minimal authentication page and Livewire counter. Guests see “Hello stranger” and cannot use the counter. Authenticated users see “Hello world” and can increment the counter. Login and logout must work."

Inspect the derived acceptance criteria and implementation files. `molly:story` prints three commands. Run the first, `php artisan molly:start TASK`, which writes the test. Then stop: show the person the written test, the derived implementation files, and the command `php artisan molly:lock-test TASK --approve`, and wait until the person has run it. That command locks the test with the derived files and records the RED baseline. Read the RED baseline, then run the third command, `php artisan molly:start TASK`, which runs the implementation through Molly's configured local model.

Verify the result independently, inspect the diff and receipts, and demonstrate stop and resume or one recovery case. For the handoff, show the person `php artisan molly:handoff TASK --from UUID --to UUID --approve` with the workspace IDs filled in, and let the person run it.

Follow the "Run tasks on two local Orbs" tutorial in docs/execution-targets.md with two independent tasks. Show both runs overlapping in separate worktrees, a busy Orb refused, and both results after `php artisan molly:worker restart --orb=ORB`.

Run <verification commands> with --json where available, and record every exit code.

Do not borrow state from an earlier session, write the application code yourself, switch providers, run a command with `--approve`, or bypass an approval. Report every mandatory acceptance ID with its evidence.
```

## Bloom

```text
Install Molly candidate <version> (zip <artifact>, sha256 <sha256>) into Bloom Dev built from Bloom commit <bloom commit>.

Read the candidate's README.md and docs/quickstart-bloom.md. Before any model download, run the hardware preflight and explain the decision: the runtime, the model it selected and why, or a truthful no-fit or unknown result. A person approves any download.

Install the plugin with `bin/molly-bloom-plugin-register`, launch Bloom Dev, and confirm that the Molly entries appear in the sidebar.

Use New project and Add existing. Submit the canonical story from Molly's Tasks screen. Observe the derived acceptance criteria and the written test. Stop and ask the person to approve the test lock; do not approve it yourself. Then observe the protected RED baseline, the real model run, the independent GREEN result, and the receipts.

Inspect the task, run, conversation, diff, graph, glossary, settings, and worker screens. Quit and relaunch Bloom to show persistence, and demonstrate one recovery case.

Run <verification entry point> and export the evidence folder.

Do not implement the sample app yourself, weaken tests, use a mock host, or approve or publish on Mary's behalf. Report the exact failing IDs if blocked.
```
