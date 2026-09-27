# Fresh-session prompts

Status: draft. Placeholders in angle brackets are filled only with verified values from the accepted candidate. Two values depend on open decisions for MME-5885: the approved model, and whether real runs on macOS use the sandbox override. Until those decisions are made, these prompts must not be handed to a fresh session as acceptance.

## Standalone CLI

```text
Install Molly candidate <version> (zip <artifact>, sha256 <sha256>, commit <commit>) into a fresh Laravel <12|13> application without Bloom.

Read README.md and docs/quickstart-standalone.md from the candidate. Before any model download, run `php artisan molly:preflight --json` and explain the runtime and model it measured, or a truthful no-fit result. Then follow the QuickStart setup commands exactly.

Create or add a disposable project. Submit this story with `php artisan molly:story`:

"Build a minimal authentication page and Livewire counter. Guests see “Hello stranger” and cannot use the counter. Authenticated users see “Hello world” and can increment the counter. Login and logout must work."

Inspect the derived acceptance criteria. Run the authoring task, approve the test lock, and read the RED baseline. Run the implementation through Molly's configured local model.

Verify the result independently, inspect the diff and receipts, demonstrate stop and resume or one recovery case, and export the handoff with `php artisan molly:handoff`.

Run <verification commands> with --json where available, and record every exit code.

Do not borrow state from an earlier session, write the application code yourself, switch providers, or bypass an approval. Report every mandatory acceptance ID with its evidence.
```

## Bloom

```text
Install Molly candidate <version> (zip <artifact>, sha256 <sha256>) into Bloom Dev built from Bloom commit <bloom commit>.

Read the candidate's README.md and docs/quickstart-bloom.md. Before any model download, run the hardware preflight and explain the selected runtime and model, or a truthful no-fit result.

Install the plugin with `bin/molly-bloom-plugin-register`, launch Bloom Dev, and confirm that the Molly entries appear in the sidebar.

Use New project and Add existing. Submit the canonical story from Molly's Tasks screen. Observe the derived acceptance criteria, the protected RED baseline, the real model run, the independent GREEN result, and the receipts.

Inspect the task, run, conversation, diff, graph, glossary, settings, and worker screens. Quit and relaunch Bloom to show persistence, and demonstrate one recovery case.

Run <verification entry point> and export the evidence folder.

Do not implement the sample app yourself, weaken tests, use a mock host, or approve or publish on Mary's behalf. Report the exact failing IDs if blocked.
```
