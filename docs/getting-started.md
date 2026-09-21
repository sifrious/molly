---
layout: default
title: Getting started
---

# Getting started

Use this page to install Molly and complete one small task from the command line.

Prefer local Ollama with no paid account? Use the dedicated [QuickStart: Ollama](ollama-quickstart.md) copy-paste path first.

## No Laravel app yet?

If you do not already have a Laravel project, use the demo installer. It is one Terminal copy-paste:

```bash
curl -fsSL https://raw.githubusercontent.com/sifrious/molly/v0.1.1/bin/molly-demo -o molly-demo
chmod +x molly-demo && ./molly-demo
```

That creates `~/molly-demo` (or a path you pass), installs Laravel + Molly, runs `php artisan molly:demo`, and prints the next doctor / start / Bloom steps. It refuses a non-empty existing path unless you pass `--force`. It does not install Bloom or call cloud.

When the installer finishes, open the folder in Bloom with **Open existing branch…**, then:

```bash
cd ~/molly-demo
php artisan molly:doctor
php artisan molly:start demo-greeting
```

## Already have a Laravel project?

You should finish with three things: `molly:doctor` passes, Molly can create a task, and you know where to read the test evidence before accepting a change.

## 1. Check the Laravel project

Molly currently expects:

- PHP 8.3 or later
- Laravel 12 or 13
- Pest 4
- PHP DOM
- A working Laravel database connection

Run:

```bash
php --version
composer show laravel/framework
vendor/bin/pest --version
```

If your project does not have Pest 4 and the Laravel plugin:

```bash
composer config allow-plugins.pestphp/pest-plugin true
composer require --dev pestphp/pest:^4 pestphp/pest-plugin-laravel:^4 --with-all-dependencies
```

For Laravel feature tests, `tests/Pest.php` should include your application test case:

```php
<?php

pest()->extend(Tests\TestCase::class)->in('Feature');
```

Run your existing tests before installing Molly:

```bash
vendor/bin/pest
```

## 2. Install Molly

From the Laravel project root:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.1.1
php artisan vendor:publish --tag=molly-config
php artisan migrate
```

Molly is a development dependency. Production installs that use `composer install --no-dev` do not install it.

Add this to the project's `.gitignore`:

```gitignore
.molly/
```

Molly uses `.molly/` for local locks, journals, and the optional Laravel knowledge database.

## 3. Optional: scaffold the greeting demo

After install, you can create the tiny first task without answering prompts:

```bash
php artisan molly:demo
```

This writes `app/Greeting.php` and `tests/Feature/GreetingTest.php` when they are missing, saves task `demo-greeting`, and prints the next doctor / start / Bloom-contract steps. It does not create a Bloom worktree.

Then continue with `molly:doctor` and `molly:start demo-greeting`, or use interactive `molly:create` below for your own task.

## 4. Pick an agent

Molly supports Amp and local Ollama. The same file limits and verification rules apply to both.

### Option A: local Ollama

Start Ollama, then install a coding model. This example uses Qwen2.5-Coder 7B:

```bash
ollama pull qwen2.5-coder:7b
ollama list
php artisan molly:setup --agent=ollama --model=qwen2.5-coder:7b
```

The default endpoint is `http://127.0.0.1:11434`. Override it with `OLLAMA_URL` if needed.

A model appearing in `ollama list` proves that it is installed. It does not prove that the model can complete a Molly task.

### Option B: Amp

Install the Amp CLI, then run:

```bash
php artisan molly:setup --agent=amp
amp mcp approve molly
amp mcp doctor molly
```

Amp owns its login credentials. Molly stores the provider choice, not your Amp password or token.

## 5. Check Molly's setup

Run:

```bash
php artisan config:clear
php artisan molly:doctor
```

Continue only when you see:

```text
Molly is ready.
```

If a check fails, use [Troubleshooting](troubleshooting.md#doctor-fails).

If your PHP build does not have `posix_setsid` and `posix_kill`, set this in `config/molly.php`:

```php
'parallel_checks' => false,
```

Then clear configuration and run `molly:doctor` again.

## 6. Create a first task

Run:

```bash
php artisan molly:create
```

For a simple first task, you can answer with something like:

| Question | Example |
| --- | --- |
| What should Molly work on? | `Add GET /molly-health returning exactly {"status":"ok"}. Preserve existing routes.` |
| Task nickname | `health-check` |
| Which Pest test should pass? | `tests/Feature/MollyHealthTest.php` |
| Which other files may Molly change? | `routes/web.php` |

Creating a task does not call the model or edit files.

Start it:

```bash
php artisan molly:start health-check
```

## 7. Read the result before accepting it

Molly prints a run ID. Inspect the saved task, the run, and your Git working tree:

```bash
php artisan molly:task health-check
php artisan molly:show RUN_ID --verbose
git status --short
```

A completed run means Molly has valid required evidence for that task. It does not mean you should commit without review.

Check these items:

- The required Pest test actually ran and passed.
- The test assertions prove the behavior you asked for.
- Tarpit has no unresolved blocking finding.
- The changed files match the task you intended.

Then run your application's broader tests:

```bash
vendor/bin/pest
```

## If the attempt fails

Read the failed evidence first:

```bash
php artisan molly:show RUN_ID --verbose
```

If another attempt makes sense:

```bash
php artisan molly:retry health-check
```

Molly keeps the earlier attempt. The default limit is three attempts total. Molly never retries automatically.

## Next

- [Manage tasks](tasks.md)
- [Understand verification](verification.md)
- [Set up the local web interface](web-interface.md)
- [Choose agents and MCP tools](agents.md)
