# Agents and MCP

Molly asks an agent for two things: a proposed change to the allowed files, and the Tarpit review of that change. The agent can be a local Ollama model or Amp. Both go through the same file limits and the same verification, and Molly does not switch between them when one fails.

You do not need MCP to run tasks from Artisan. MCP lets an editor or chat client work with Molly's tasks.

## Ollama

Ollama is the default. Let Molly decide which approved model fits this Mac, install it, and save its name:

```bash
php artisan molly:preflight
php artisan molly:install-model
php artisan molly:setup --agent=ollama --model=MODEL
php artisan config:clear
php artisan molly:doctor
```

[Ollama](ollama-quickstart.md) covers the fit decision, the approved catalogue, the endpoint, and the doctor codes.

## Amp

To use Amp through its CLI and your Amp account:

```bash
php artisan molly:setup --agent=amp
amp mcp approve molly
amp mcp doctor molly
php artisan config:clear
php artisan molly:doctor
```

Amp keeps its own login and model choice; Molly stores only the provider name. The `amp` executable must be on the `PATH` of the process running Molly. Doctor reports `amp_ready` or `amp_unavailable` after checking `amp usage`, without printing account details.

When Amp writes a proposal or a review for Molly, tools, MCP access, IDE access, and remote thread creation are turned off. Molly applies the returned edits itself and runs Pest locally.

## Chat with Amp and Molly's tools

```bash
php artisan molly:chat
```

This opens Amp with Molly's MCP server attached, so you can create and read tasks from the conversation. It does not change which agent runs tasks. `--json` prints the launch arguments without opening Amp.

## Connect another MCP client

Molly's MCP server speaks stdio from the application root:

```bash
php artisan mcp:start molly
```

It is registered only in the `local` and `testing` environments and has no HTTP route.

## MCP tools

| Tool | What it does |
| --- | --- |
| `molly_task` | Creates tasks, reads tasks and runs, names tasks, links Amp threads, imports GitHub issues, asks for advice, prints a pull request description, and queues a start or retry. It refuses human decisions. |
| `molly_plan` | Creates, reads, and answers plans, and requests a Jev suggestion. |
| `molly_guide` | Reads Molly's bundled planning guide and the passages it cites. |
| `molly_knowledge` | Reads the local Laravel, NativePHP, or Tarpit knowledge graph. |
| `molly_connections` | Reads saved Amp thread links and, optionally, Amp's current connection state. |

A start or retry from MCP is queued through the application's queue and needs a worker. A queued reply means the work was dispatched, not that it ran. [Web interface](web-interface.md#queue-requirements) lists the supported queue drivers.

## Human decisions

An MCP client is an agent, so `molly_task` does not record a human decision. The `approve`, `lock_test`, `pr_opened`, `merged`, `comment`, and `handoff` operations fail with `HUMAN_APPROVAL_REQUIRED` and change nothing: no lifecycle event, task field, journal, or GitHub request. Passing `approve=true` makes no difference. The error ends with the Artisan command for a person to run, filled in with the arguments the client passed:

```text
HUMAN_APPROVAL_REQUIRED: Only a person can approve a verified change. molly_task changed nothing. Ask a person to run: php artisan molly:approve ready-check --approve
```

| Operation | Command a person runs |
| --- | --- |
| `approve` | `molly:approve TASK --approve` |
| `lock_test` | `molly:lock-test TASK --approve [--file=PATH] [--reason=TEXT]` |
| `pr_opened` | `molly:pr-opened TASK --url=URL --approve` |
| `merged` | `molly:merged TASK --sha=SHA --approve` |
| `comment` | `molly:comment TASK --approve [--close]` |
| `handoff` | `molly:handoff TASK --from=UUID --to=UUID [--action=ACTION] [--context=TEXT] --approve` |

When the client leaves out the pull request URL, merge SHA, or workspace UUIDs, the command shows `URL`, `SHA`, or `UUID` in its place. After a person runs the command, `show` reports the new display status and `pr_body` prints the pull request description.

## Knowledge in prompts

When the Laravel knowledge graph is indexed, Molly attaches a small neighborhood to each implementation prompt, chosen from the task, its files, and its test. It adds NativePHP knowledge when the task mentions desktop or mobile, and Tarpit notes when it mentions cleverness or complexity. This context is advisory: it cannot widen the allowed files, change the protected test, or complete a task. See [Laravel knowledge](knowledge-graph.md).

## Jev through Laravel AI

Jev is optional and off by default. When you enable it, Molly may ask TypeSafe's Jev model one bounded question, through Laravel AI's classification provider, in three places: which planning area needs another look, what to do after a failed run, and whether a PHP commit has a semantic problem.

```dotenv
MOLLY_JEV_ENABLED=true
TYPESAFE_API_KEY=...
```

Jev answers with a choice and a confidence. Below the configured threshold, Molly keeps its own deterministic guidance and says so. Jev never changes a Pest or Tarpit result, never widens retries, and never completes a task. [Task advice](task-advice.md#advice-from-jev) shows what it looks like, and the [configuration reference](reference/configuration.md#jev) lists the settings and every gate state doctor can report.

## Review a commit

```bash
php artisan molly:review-commit HEAD
php artisan molly:review-commit --staged --json
```

This reads a bounded PHP diff, runs Git's whitespace check, and, when Jev is enabled, asks for a semantic review. It does not run tests or create commits. The command exits `1` when the whitespace check fails or a required review could not be performed.

## Limits

An Amp thread link is a note you save; Molly does not verify that the thread exists, and a thread is not an Orb. MCP `molly_task` can queue a start or retry on a registered local Orb with `orb`, `orb_runtime`, or `orb_model`; choosing where a task runs is not a human decision. Registering and revoking Orbs are Artisan commands. [Execution targets](execution-targets.md) covers Orbs.

Molly treats the terminal as the person's. It refuses human decisions from MCP clients, but it cannot tell whether a person or an agent with shell access typed an Artisan command. Do not give an agent a shell where it can run `--approve` commands for you.

## Next

- [Getting started](getting-started.md)
- [Task connections](connections.md)
- [Commands](reference/commands.md)
