---
layout: default
title: Getting started
---

# Getting started

Run Molly in a trusted Laravel application on your computer. This walkthrough installs Molly, selects Amp or local Ollama, and asks Molly to add a small JSON endpoint with a required Pest test.

Molly is available from the `main` branch. No alpha release is tagged yet. If you choose Ollama, the first model download can take longer than the rest of the setup.

## Prepare a Laravel application

Use PHP 8.3 or later, Composer, and a Laravel 13 application with a working database connection. PHP must include the DOM extension. Parallel checks also require `posix_setsid` and `posix_kill`. If your PHP installation lacks these functions, the doctor step below explains how to select serial checks.

Work in a disposable checkout. Molly applies generated PHP and executes the selected test file with your user account's permissions. The allowed file list limits Molly's edits; the list does not sandbox executed code.

Run the commands below from the application's root, where `artisan` and `composer.json` live. Check the installed versions:

```bash
php --version
composer show laravel/framework
```

If the application does not have Pest 4 and its Laravel plugin, install both:

```bash
composer config allow-plugins.pestphp/pest-plugin true
composer require --dev pestphp/pest:^4 pestphp/pest-plugin-laravel:^4 --with-all-dependencies
```

Add the following binding to `tests/Pest.php`. Create the file if necessary, and keep any existing setup:

```php
<?php

pest()->extend(Tests\TestCase::class)->in('Feature');
```

The binding gives Pest feature tests access to Laravel's application test case. Run the application's existing tests before asking Molly to change the application:

```bash
vendor/bin/pest
```

## Install Molly

Add the public repository to Composer and install the development branch:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:dev-main
php artisan vendor:publish --tag=molly-config
php artisan migrate
```

Composer installs Laravel AI, Laravel MCP, Laravel Prompts, free Flux, and Livewire with Molly. Clever commands are included in Molly. No separate Clever package is required. Installing Molly with `--dev` keeps Molly out of production installs that use `composer install --no-dev`.

The publish command creates `config/molly.php` and `config/molly-complexity.php`. The migrations create task, run, and plan history in the application's configured database. When upgrading, run `php artisan migrate` to add any missing task nickname, snapshot, planning, thread-association, and journal-status storage. Review other pending application migrations before running `migrate` in an existing project.

Add this entry to the application's `.gitignore`:

```gitignore
.molly/
```

Molly uses the directory for workspace and task locks.

## Choose an agent

Set `APP_ENV=local` in the application's `.env`. Choose one provider for code proposals and Tarpit review. Molly applies edits and runs Pest locally with either choice. There is no automatic provider fallback.

### Use Amp

With the official Amp CLI installed, run:

```bash
php artisan molly:setup --agent=amp
```

Setup opens Amp's login flow in an interactive terminal and saves a workspace MCP connection. Amp owns the credentials; Molly saves the agent choice in `.env`. Complete the follow-up commands printed by setup:

```bash
amp mcp approve molly
amp mcp doctor molly
```

This option sends the selected task content to your Amp account's service. Molly's proposal and review requests disable Amp tools and remote execution. Amp execution has a recorded live acceptance check; the public Ollama example below still needs one. Read [agent setup](agents.md) for noninteractive setup, chat, and MCP details. Continue to [check the environment](#check-the-environment) after setup.

### Use local Ollama

Install [Ollama for your operating system](https://ollama.com/download). Open the Ollama application. If you use a terminal installation without a running server, start the server in a separate terminal:

```bash
ollama serve
```

Keep that terminal open. An address-in-use message usually means an Ollama server already owns the port. Check the running server with `ollama list` before starting another. The [Ollama CLI reference](https://docs.ollama.com/cli) documents server and model commands.

Download a local model. The following example uses [Qwen2.5-Coder 7B](https://ollama.com/library/qwen2.5-coder:7b), whose published download is about 4.7 GB. Running the model needs additional memory.

```bash
ollama pull qwen2.5-coder:7b
ollama list
```

`qwen2.5-coder:7b` should appear in the model list. Molly has not yet completed a live acceptance check with this public setup example. The recorded Molly demo used a locally customized `gpt-oss:120b-code` model. That custom name is not a public model to download.

Molly asks Ollama for schema-constrained JSON for both file edits and Tarpit review. See [Ollama structured outputs](https://docs.ollama.com/capabilities/structured-outputs) for the underlying API. A model appearing in `ollama list` confirms installation, not the model's ability to complete a Molly task.

Set the local endpoint in the Laravel application's `.env`:

```dotenv
APP_ENV=local
OLLAMA_URL=http://127.0.0.1:11434
```

Save the installed model choice:

```bash
php artisan molly:setup --agent=ollama --model=qwen2.5-coder:7b
```

Setup writes `MOLLY_AGENT=ollama` and `MOLLY_LOCAL_MODEL=qwen2.5-coder:7b` to `.env`. If you already have another suitable local model, use the exact name from `ollama list`. Molly does not download models or change to Amp without your selection.

## Check the environment

Clear cached configuration and run doctor:

```bash
php artisan config:clear
php artisan molly:doctor
```

Doctor checks task and run storage, Pest, the selected provider, bundled measurements, and POSIX functions for parallel checks. With Amp, doctor runs `amp usage` to check account access. With Ollama, doctor checks the loopback endpoint and installed model. Every listed check must pass. A ready environment ends with:

```text
Molly is ready.
```

Doctor checks setup without asking the model to generate code. For a failed check, read the [troubleshooting guide](troubleshooting.md#doctor-reports-a-failed-check).

If POSIX functions are unavailable, set `parallel_checks` to `false` in `config/molly.php`:

```php
'parallel_checks' => false,
```

Run `php artisan config:clear` and `php artisan molly:doctor` again. Serial mode runs the same Pest and Tarpit checks one after the other. Molly never switches modes silently.

## Create your first task

Start the interactive task form:

```bash
php artisan molly:create
```

Molly asks for the task, an optional nickname, the required Pest test, and other files Molly may change. For a small first task, use these answers. Choose an endpoint, nickname, and test filename that do not already exist in your application:

| Question | Example answer |
| --- | --- |
| What should Molly work on? | `Add GET /molly-health returning exactly {"status":"ok"}. Preserve existing routes.` |
| Task nickname | `health-check` |
| Which Pest test should pass? | `tests/Feature/MollyHealthTest.php` |
| Which other files may Molly change? | `routes/web.php` |

Selecting the test also permits Molly to add or update that test. Enter other file paths separated by commas. For a task that only changes the test, leave the other-files answer empty.

Paths stay relative to the workspace. A selected file may be new. Molly creates missing parent directories when applying valid edits. Molly asks only for missing inputs, so you can also supply the task or paths as [command options](reference/commands.md#scope-and-output-options).

Creation saves a `pending` task without calling the model or editing the selected files. Use the nickname to inspect and start the task:

```bash
php artisan molly:task health-check
php artisan molly:start health-check
```

The start command runs in the foreground. No queue worker or web server is needed for CLI execution. If you left the nickname empty, use the task UUID printed by Molly. UUIDs remain valid for named tasks too.

## Read the result

Molly measures complexity, asks the model for edits, and applies the proposal. Pest and Tarpit review then run in parallel unless you selected serial mode. Molly measures complexity again and saves the results.

A successful report identifies the run as `completed` and ends with:

```text
Task completed. Review the changed files before committing.
```

Check the evidence before accepting the edit:

- Pest must report at least one executed test and a passing result. Read the actual assertion count and test output.
- Tarpit must report all seven checks. An unresolved blocking finding prevents completion.
- Clever shows before and after measurements separately. Read any skipped probe and its reason. Smaller counts alone do not prove a simpler design.
- In parallel mode, both `verification` and `review` branches must pass and record results.

The run ID identifies one attempt. The task nickname or UUID identifies the saved task and its attempt history. Replace `RUN_ID` with the run ID from the report:

```bash
php artisan molly:show RUN_ID --verbose
php artisan molly:show RUN_ID --json
git diff -- routes/web.php
git status --short
```

Open `tests/Feature/MollyHealthTest.php` as well. Git does not include a new, untracked test file in ordinary `git diff` output. Molly runs only the required test file, so run the application's broader suite before committing:

```bash
vendor/bin/pest
```

## Handle a failed attempt

An attempt may fail because the generated code does not pass Pest, the review finds a blocker, or a required check cannot run. A passing review does not override failed tests. Applied edits remain in the workspace after failed verification.

Read the failed report and the changed files. After resolving setup errors, you can ask Molly to retry the same task:

```bash
php artisan molly:retry health-check
```

The retry receives a bounded excerpt of the previous failure evidence. Each retry creates a new run and preserves the earlier report. The default limit is three attempts total. Molly never retries automatically.

Continue with [planning a collection of tasks](planning.md), [task management](tasks.md), [verification and complexity review](verification.md), or the [local web interface](web-interface.md).
