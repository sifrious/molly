<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Sifrious\Molly\Agents\LocalOllama;
use Sifrious\Molly\Complexity\Clever;
use Throwable;

class CheckEnvironment
{
    /** @return array{ready: bool, checks: list<array{name: string, status: string, code: string, message: string}>} */
    public function handle(string $workspace): array
    {
        $checks = [];
        $add = static function (string $name, bool $passed, string $code, string $message) use (&$checks): void {
            $checks[] = ['name' => $name, 'status' => $passed ? 'passed' : 'failed', 'code' => $code, 'message' => $message];
        };

        try {
            $ready = Schema::hasTable('molly_runs') && Schema::hasTable('molly_tasks') && Schema::hasColumn('molly_tasks', 'nickname');
            $add('Run history', $ready, $ready ? 'database_ready' : 'migration_missing', $ready ? 'Task and run history are ready.' : 'Run php artisan migrate to update Molly task and run history.');
        } catch (Throwable) {
            $add('Run history', false, 'database_unavailable', 'Molly could not connect to the configured database.');
        }

        $workspace = realpath($workspace);
        $pest = $workspace !== false && is_file($workspace.'/vendor/bin/pest');
        $add('Pest', $pest, $pest ? 'pest_ready' : 'pest_missing', $pest ? 'Pest is installed in the workspace.' : 'Install Pest in the workspace before running a task.');

        if (config('molly.parallel_checks', true) === true) {
            $available = function_exists('posix_setsid') && function_exists('posix_kill');
            $add('Parallel checks', $available, $available ? 'parallel_process_groups_ready' : 'parallel_process_groups_unavailable', $available
                ? 'PHP provides the POSIX functions required to start and stop parallel checks.'
                : 'Parallel checks require posix_setsid and posix_kill. Enable these PHP functions or set molly.parallel_checks to false to run checks serially.');
        }

        $url = (string) config('ai.providers.ollama.url', '');
        $model = trim((string) config('molly.model', ''));
        $valid = false;
        try {
            LocalOllama::validate();
            $valid = true;
            $add('Local provider and model', true, 'ollama_configured', 'Laravel AI uses the local Ollama model '.$model.'.');
        } catch (Throwable) {
            $code = $model === '' ? 'model_not_configured' : (str_contains($model, 'cloud') ? 'model_not_local' : 'ollama_config_invalid');
            $add('Local provider and model', false, $code, 'Set a local MOLLY_LOCAL_MODEL, an Ollama driver, a loopback HTTP URL, and a positive integer molly.timeout.');
        }

        if ($valid) {
            try {
                $response = Http::timeout(5)->withoutRedirecting()->get(rtrim($url, '/').'/api/tags');
                $models = $response->json('models');
                if (! $response->successful() || ! is_array($models)) {
                    $add('Ollama', false, 'ollama_response_invalid', 'Ollama did not return a valid model list.');
                } else {
                    $add('Ollama', true, 'ollama_reachable', 'Ollama is responding.');
                    if ($model !== '') {
                        $names = array_column($models, 'name');
                        $installed = in_array($model, $names, true) || in_array($model.':latest', $names, true);
                        $add('Installed model', $installed, $installed ? 'model_ready' : 'model_missing', $installed ? 'The requested model is installed.' : 'Ollama does not have the requested model: '.$model);
                    }
                }
            } catch (Throwable) {
                $add('Ollama', false, 'ollama_unreachable', 'Molly could not reach Ollama. Start Ollama and try again.');
            }
        }

        try {
            $enabled = app(Clever::class)->enabled();
            $add('Clever', $enabled, $enabled ? 'clever_ready' : 'clever_disabled', $enabled ? 'Bundled Clever measurements are enabled.' : 'Enable molly-complexity.enabled outside production to measure complexity.');
        } catch (Throwable) {
            $add('Clever', false, 'clever_unavailable', 'Molly could not load the bundled Clever measurements.');
        }

        return ['ready' => ! in_array('failed', array_column($checks, 'status'), true), 'checks' => $checks];
    }
}
