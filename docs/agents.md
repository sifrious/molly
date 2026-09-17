---
layout: default
title: Choose an agent and connect MCP
---

# Choose an agent and connect MCP

Choose Amp or an installed local Ollama model for Molly's code proposals and Tarpit reviews. Molly applies the same file-scope validation, Pest checks, and completion rules to either provider. Molly does not switch providers after a failed request.

## Set up Amp

Install the official Amp CLI and run setup from the host Laravel application:

```bash
php artisan molly:setup --agent=amp
```

In an interactive terminal, setup runs `amp login`, then saves a workspace MCP connection named `molly`. Amp manages the login credentials. Molly writes `MOLLY_AGENT=amp` to the host application's `.env`; Molly does not copy Amp credentials into the database or environment file.

Run the approval and connection check printed by setup:

```bash
amp mcp approve molly
amp mcp doctor molly
php artisan config:clear
php artisan molly:doctor
```

Amp requires approval for workspace MCP configuration. Its [MCP documentation](https://ampcode.com/docs/customize/mcp) explains that trust step. A saved connection alone does not prove that login or tool discovery succeeded.

Use `--no-login` to save the connection without opening the login flow. For scripts, provide `--agent=amp --json --no-interaction`. Noninteractive setup returns the login and follow-up commands instead of waiting for a browser login. Setup still changes the workspace MCP configuration and Molly's `.env` setting.

`--model` is only for Ollama and is rejected for Amp. Amp controls its model selection. Molly leaves model metadata unknown when the CLI does not supply a model name.

## Set up local Ollama

Start Ollama and install a suitable local model as described in [getting started](getting-started.md#use-local-ollama). Then select a model already installed on that server:

```bash
php artisan molly:setup --agent=ollama --model=YOUR_INSTALLED_MODEL
```

Interactive setup can list the installed models and ask you to choose one. The command saves `MOLLY_AGENT=ollama` and `MOLLY_LOCAL_MODEL`. Unattended setup requires both options. Molly rejects cloud model names and accepts only a loopback HTTP Ollama endpoint.

Setup validates the existing environment file before writing. Duplicate keys, invalid syntax, symbolic links, and layouts that would change unrelated values are rejected. Correct the reported problem or edit the Molly values manually, then clear cached configuration. See the [configuration reference](reference/configuration.md).

## Understand Amp task execution

With `MOLLY_AGENT=amp`, `src/Actions/GenerateChanges.php` and `src/Actions/ReviewChanges.php` send the task and selected file contents through `src/Agents/AmpResponse.php`. These requests use your Amp account and service. Molly still applies edits and runs Pest locally.

For each request, Molly creates a temporary working directory and Amp settings that disable tools, MCP connections, IDE access, and remote thread creation. The response must report an empty tool list, no attempted tool call, valid structured output, and an explicit successful terminal result. Molly removes the temporary directory afterward. The provider timeout comes from `molly.timeout`.

The [Amp configuration](https://ampcode.com/docs/cli/settings) and [streaming JSON reference](https://ampcode.com/docs/cli/streaming-json) describe the underlying CLI controls and events. This execution path does not launch an Orb or let Amp edit the selected workspace directly.

An interactive Amp chat has its own tool access. The restricted proposal requests above are separate from the chat described below.

## Open Amp with Molly connected

```bash
php artisan molly:chat
```

The command opens Amp's terminal interface with a session-level Molly MCP configuration. Amp keeps its other configured servers. `molly:chat` always opens Amp; it does not change the provider selected for Molly task execution.

The command needs an interactive terminal. Use `--json` to inspect the launch arguments and workspace without starting a chat:

```bash
php artisan molly:chat --json
```

For another MCP client, configure a local stdio server that runs the following command in the host application's directory:

```bash
php artisan mcp:start molly
```

Molly registers the server only in `local` and `testing` environments. There is no HTTP MCP route, and enabling `MOLLY_UI_ENABLED` is not required for this stdio server.

## MCP tools

The server uses the same application actions as the CLI and web interface:

| Tool | Operations | Result |
| --- | --- | --- |
| `molly_guide` | `graph`, `source` | Reads the offline guide or one source passage with its citation, revision, and digest. `source` requires a source `id` from the graph. |
| `molly_plan` | `create`, `show`, `answer`, `suggest` | Saves or reads a plan, answers its current question, or records an optional Jev suggestion. `create` accepts `description` and optional `guided`; the other operations require `id`. `answer` also needs `step` and `answer`. |
| `molly_task` | `list`, `show`, `create`, `from_plan`, `import_github`, `name`, `link_thread`, `advice`, `start`, `retry`, `stop`, `show_run` | Saves tasks and names, records thread links, reads evidence, returns advice, or requests task lifecycle changes. Creation and linking do not execute code. |
| `molly_connections` | Read only | Accepts `task`, a nickname or UUID, and optional `stored`. Returns the same task-thread associations and Amp observation report as the CLI. `stored: true` skips Amp; the default is `false`. |

Task creation needs `workspace` and `test_path`. Optional `paths` lists additional files; Molly includes the required test automatically. Use `prompt` for `create` and `from_plan`; `from_plan` also needs `plan_id`. GitHub import needs `issue_url` and uses the host's `gh` login. All three creation operations accept an optional `nickname`. The normal [task scope limits](tasks.md#create-a-task) still apply.

Task operations use `id` for a nickname or task UUID; `show_run` instead requires a run UUID. `name` needs `nickname`, and `link_thread` needs an Amp thread ID in `thread`. `advice` checks saved state and limits, may request optional TypeSafe guidance, and can save advice without starting work. Read [task advice](task-advice.md) for the conditions and fallback behavior.

Task listing accepts `limit` from 1 through 100 and defaults to 20. Structured task responses use `task`, `tasks`, `run`, `association`, or `advice` for the requested operation. Plan responses contain `plan`, `completed`, `next_step`, and `sources`. Invalid inputs and rejected operations return an MCP error; an unavailable Amp observation remains a connection report with an explicit status and reason.

`start` and `retry` queue requests through `src/Actions/QueueTask.php`. Configure a supported queue connection and run a host queue worker using the [web queue setup](web-interface.md#enable-the-interface). `queued: true` means that Molly dispatched a request. Read the task and run afterward to establish whether execution started or completed.

`molly_connections` is marked read-only. A saved thread link remains a user assertion, and a connected Amp executor remains an unverified Orb identity. The tools do not connect or disconnect Orbs or select remote execution targets. The [execution target plan](execution-targets.md) describes that remaining work.

## Review a PHP commit

The commit review command checks a bounded PHP diff and can request a separate TypeSafe evaluation:

```bash
php artisan molly:review-commit HEAD
php artisan molly:review-commit --staged --json
```

Choose a commit reference or `--staged`, not both. `--workspace` selects another existing Git checkout. The command reads PHP changes, excludes vendor and environment files, runs Git's whitespace check, and reports the diff size and digest. The command does not edit files, run tests, or create a commit.

Merge commits are compared with their first parent using `--diff-merges=first-parent`. This includes changes introduced by the merge, even when Git's default combined diff would omit those changes. See the [Git show reference](https://git-scm.com/docs/git-show) for the diff mode.

TypeSafe is optional and disabled by default. Enable the [TypeSafe settings](reference/configuration.md#typesafe-evaluation-settings) to send the requested diff with cited Tarpit and Laravel guidance to Jev. Molly uses a direct HTTP adapter to the [TypeSafe API](https://docs.typesafe.ai/api). No TypeSafe SDK or JavaScript bridge is installed. Laravel AI `0.11.2` does not supply a native TypeSafe classification API for this integration.

Diffs larger than 16 KiB and encoded evaluation evidence larger than 32 KiB are rejected before evaluation. Low confidence, provider errors, and invalid responses produce `needs_review`.

`continue` means that the semantic review identified no blocker in the supplied diff. It does not prove that tests pass. `retry`, `stop`, and `needs_review` produce a nonzero exit. A Git whitespace failure also produces a nonzero exit. A disabled evaluation or a diff with no PHP changes can exit zero if the whitespace check passes; those reports remain explicitly unevaluated.

Run the relevant tests and inspect the diff before committing. This command installs no Git hook and starts no automatic retry loop.
