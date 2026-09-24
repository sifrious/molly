# QuickStart: Molly on its own

The shortest path from a Laravel application to a completed Molly task, without Bloom or a paid AI account. [Getting started](getting-started.md) explains each step; this page is the copy-paste version.

From the application root, with [Ollama](https://ollama.com) installed and Pest in the application:

```bash
composer config repositories.molly vcs https://github.com/sifrious/molly
composer require --dev sifrious/molly:^0.1.1
php artisan vendor:publish --tag=molly-config
php artisan migrate
ollama pull qwen2.5-coder:7b
php artisan molly:setup --agent=ollama --model=qwen2.5-coder:7b
php artisan config:clear
php artisan molly:doctor
php artisan molly:demo
php artisan molly:start demo-greeting
```

On macOS, doctor reports `sandbox_unavailable`. Add `MOLLY_SANDBOX_ALLOW_UNSAFE=true` to `.env` for a checkout you trust, then clear the configuration cache and run doctor again; [Getting started](getting-started.md#macos-and-the-sandbox) says what that trades away.

Then read the result:

```bash
php artisan molly:show RUN_ID --verbose
git diff
vendor/bin/pest
```

No Laravel application yet? The demo installer creates one:

```bash
curl -fsSL https://raw.githubusercontent.com/sifrious/molly/v0.1.3/bin/molly-demo -o molly-demo
bash molly-demo ~/molly-demo
```

Next: [Getting started](getting-started.md), [Tasks](tasks.md), [Molly on its own](standalone.md).
