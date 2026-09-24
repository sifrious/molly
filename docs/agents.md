# Agents and MCP

Molly asks an agent for two things: a proposed change to the allowed files, and the Tarpit review of that change. The agent can be a local Ollama model or Amp. Both go through the same file limits and the same verification, and Molly does not switch between them when one fails.

You do not need MCP to run tasks from Artisan. MCP lets an editor or chat client work with Molly's tasks.

## Ollama

Ollama is the default. Pull a model and save its name:

```bash
php artisan molly:setup --agent=ollama --model=qwen2.5-coder:7b
php artisan config:clear
php artisan molly:doctor
```

[Ollama](ollama-quickstart.md) covers choosing a model, the endpoint, and the doctor codes.

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
| `molly_task` | Creates tasks, reads tasks and runs, names tasks, links Amp threads, imports GitHub issues, asks for advice, records approvals, locks a written test, records pull requests and merges, and queues a start or retry. |
| `molly_plan` | Creates, reads, and answers plans, and requests a Jev suggestion. |
| `molly_guide` | Reads Molly's bundled planning guide and the passages it cites. |
| `molly_knowledge` | Reads the local Laravel, NativePHP, or Tarpit knowledge graph. |
| `molly_connections` | Reads saved Amp thread links and, optionally, Amp's current connection state. |

Operations that record a human decision (`approve`, `lock_test`, `pr_opened`, `merged`, `comment`) require `approve=true`. None of them opens or merges a pull request.

A start or retry from MCP is queued through the application's queue and needs a worker. A queued reply means the work was dispatched, not that it ran. [Web interface](web-interface.md#queue-requirements) lists the supported queue drivers.

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

An Amp thread link is a note you save; Molly does not verify that the thread exists or that its executor is an Orb. Remote execution is planned and described in [Execution targets](execution-targets.md).

## Next

- [Getting started](getting-started.md)
- [Task connections](connections.md)
- [Commands](reference/commands.md)
