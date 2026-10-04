<?php

namespace Sifrious\Molly\Acceptance;

use Illuminate\Support\Facades\Process;
use JsonException;
use Sifrious\Molly\Actions\GetMollySettings;
use Sifrious\Molly\Actions\ListConversations;
use Sifrious\Molly\Actions\ListMollyProjects;
use Sifrious\Molly\Actions\ListTasks;
use Sifrious\Molly\Actions\QueryProjectGraph;
use Sifrious\Molly\Actions\ShowRun;
use Sifrious\Molly\Journal\JournalRenderer;
use Sifrious\Molly\Models\Task;
use Throwable;

/**
 * Runs the M04 assertions that can be read from services, storage, and host
 * logs. A remaining Bloom UI observation stays needs_native.
 */
final class BloomProgrammaticAssertions
{
    public function __construct(
        private ListMollyProjects $projects,
        private ListTasks $tasks,
        private ShowRun $runs,
        private ListConversations $conversations,
        private GetMollySettings $settings,
        private QueryProjectGraph $graph,
        private JournalRenderer $glossary,
    ) {}

    public function probe(VerificationCheckDefinition $check, VerificationContext $context): ProbeResult
    {
        return match ($check->id) {
            'M04.1' => $this->compiledHost($context),
            'M04.2' => $this->project($context, 'new', 'M04.2 lists a project whose source is new.'),
            'M04.3' => $this->project($context, 'existing', 'M04.3 lists a project whose source is existing.'),
            'M04.4' => $this->tasks($context),
            'M04.5' => $this->runSurface($context),
            'M04.6' => $this->conversation($context),
            'M04.7' => $this->graphSurface($context),
            'M04.8' => $this->glossarySurface($context),
            'M04.9' => $this->settingsMatch($context),
            'M04.10' => $this->worker($context),
            'M04.11' => $this->trace($context),
            'M04.12' => $this->cliVisible($context),
            'M04.13' => $this->hostLog($context, 'host-incompat.log', 'INCOMPAT_LOG', 'skipped', 'apiVersion', 'stable'),
            'M04.14' => $this->missingHost($context),
            'M04.15' => $this->brokenAssets($context),
            'M04.16' => $this->restart($context),
            default => ProbeResult::complete(
                CheckOutcome::EvidenceIncomplete,
                'EVIDENCE_NOT_OBSERVED',
                'No '.$check->id.' assertion is defined, so Molly did not call the check a pass.',
            ),
        };
    }

    private function compiledHost(VerificationContext $context): ProbeResult
    {
        $seam = $this->seam($context);
        if ($seam->result === 'fail') {
            return $this->fail('PLUGIN_SEAM_FAILED', $seam->actual, [$seam]);
        }
        $telemetry = $this->telemetryPath($context);
        if ($telemetry === null) {
            return ProbeResult::complete(
                CheckOutcome::BlockedPrerequisite,
                'COMPILED_HOST_NOT_OBSERVED',
                'The extension seam matches. The compiled Bloom host has not been observed, so M04.1 is not accepted and is not a Molly product failure.',
                [],
                [$seam],
            );
        }
        $decoded = json_decode((string) file_get_contents($telemetry), true);
        $discovered = is_array($decoded) && ($decoded['plugin_discovered'] ?? false) === true && ($decoded['plugin_id'] ?? null) === 'sifrious.molly';
        $host = $this->assertion(
            $context,
            'host telemetry names sifrious.molly',
            'sifrious.molly discovered',
            $discovered ? 'sifrious.molly discovered' : 'plugin not discovered',
            $telemetry,
            $discovered ? 'pass' : 'fail',
        );
        if (! $discovered) {
            return $this->fail('HOST_PLUGIN_NOT_DISCOVERED', 'Host telemetry does not say sifrious.molly was discovered.', [$seam, $host]);
        }

        return ProbeResult::needsNative(
            'HOST_UI_NOT_OBSERVED',
            'The extension seam matches and host telemetry says sifrious.molly was discovered. Nav visibility still needs a compiled-host observation.',
            [$seam, $host],
        );
    }

    private function project(VerificationContext $context, string $source, string $assertion): ProbeResult
    {
        if ($context->projectRoot === '') {
            return $this->incomplete('PROJECT_NOT_OBSERVED', 'Molly has no project directory to compare with molly:projects.', [
                $this->assertion($context, $assertion, 'source '.$source, 'no project directory', '', 'not_observed'),
            ]);
        }
        try {
            $listed = $this->projects->handle();
        } catch (Throwable $exception) {
            return $this->incomplete('PROJECT_LIST_FAILED', $exception->getMessage(), [
                $this->assertion($context, $assertion, 'source '.$source, $exception->getMessage(), 'ListMollyProjects', 'not_observed'),
            ]);
        }
        $match = null;
        foreach ($listed as $project) {
            if ($project->source === $source) {
                $match = $project;
                break;
            }
        }
        if ($match === null) {
            return $this->incomplete('PROJECT_NOT_OBSERVED', 'molly:projects has no project whose source is '.$source.'.', [
                $this->assertion($context, $assertion, 'source '.$source, 'none listed', 'ListMollyProjects', 'not_observed'),
            ]);
        }
        $record = $this->assertion(
            $context,
            $assertion,
            'source '.$source,
            $match->id.' source '.$match->source,
            $match->path.'/.molly/project.json',
            'pass',
        );

        return ProbeResult::needsNative(
            'PROJECT_UI_NOT_OBSERVED',
            'molly:projects lists the '.$source.' project. The Bloom project action still needs an Accessibility observation.',
            [$record],
        );
    }

    private function tasks(VerificationContext $context): ProbeResult
    {
        $task = $this->task($context);
        if (! $task instanceof Task) {
            return $this->incomplete('TASK_NOT_OBSERVED', 'molly:tasks has no task for this project.', [
                $this->assertion($context, 'a task is listed for the project', 'one task', $task ?? 'none', 'ListTasks', 'not_observed'),
            ]);
        }

        return $this->pass('TASK_LISTED', 'molly:tasks lists '.$task->reference().'.', [
            $this->assertion($context, 'a task is listed for the project', 'one task', $task->id, 'database:molly_tasks', 'pass'),
        ]);
    }

    private function runSurface(VerificationContext $context): ProbeResult
    {
        $run = $this->run($context);
        if ($run === null) {
            return $this->incomplete('RUN_NOT_OBSERVED', 'Molly has no saved run to show for this project.', [
                $this->assertion($context, 'molly:show returns run status and verifier results', 'status and verification', 'no run', 'ShowRun', 'not_observed'),
            ]);
        }
        $shown = $this->runs->handle($run['id']);
        $report = is_array($shown?->report) ? $shown->report : [];
        $status = is_string($shown?->status) ? $shown->status : '';
        $verification = $report['verification'] ?? null;
        $ok = $status !== '' && is_array($verification);
        $assertion = $this->assertion(
            $context,
            'molly:show returns run status and verifier results',
            'status and verification',
            $ok ? $status : 'missing status or verification',
            'database:molly_runs/'.$run['id'],
            $ok ? 'pass' : 'fail',
        );

        return $ok
            ? $this->pass('RUN_STATUS_SHOWN', 'molly:show returned '.$status.' with verifier results.', [$assertion])
            : $this->fail('RUN_STATUS_MISSING', 'The saved run does not include a status and verifier results.', [$assertion]);
    }

    private function conversation(VerificationContext $context): ProbeResult
    {
        $task = $this->task($context);
        if (! $task instanceof Task) {
            return $this->incomplete('CONVERSATION_NOT_OBSERVED', 'Molly has no task whose conversation can be listed.', [
                $this->assertion($context, 'conversation links to the task', 'task id', 'no task', 'ListConversations', 'not_observed'),
            ]);
        }
        $listed = $this->conversations->handle($task->id);
        $match = null;
        foreach ($listed['conversations'] as $conversation) {
            if (in_array($task->id, $conversation['task_ids'] ?? [], true)) {
                $match = $conversation;
                break;
            }
        }
        if ($match === null) {
            return $this->incomplete('CONVERSATION_NOT_OBSERVED', 'Molly has no conversation linked to '.$task->id.'.', [
                $this->assertion($context, 'conversation links to the task', $task->id, 'none', (string) ($listed['path'] ?? 'ListConversations'), 'not_observed'),
            ]);
        }

        return $this->pass('CONVERSATION_LINKED', 'The conversation '.$match['id'].' links to the task.', [
            $this->assertion($context, 'conversation links to the task', $task->id, (string) $match['id'], (string) $listed['path'], 'pass'),
        ]);
    }

    private function graphSurface(VerificationContext $context): ProbeResult
    {
        if ($context->projectRoot === '') {
            return $this->incomplete('GRAPH_NOT_OBSERVED', 'Molly has no project whose graph can be queried.', [
                $this->assertion($context, 'graph nodes carry provenance', 'nodes with provenance', 'no project', 'QueryProjectGraph', 'not_observed'),
            ]);
        }
        try {
            $result = $this->graph->handle('Task', $context->projectRoot, 1, 20);
        } catch (Throwable $exception) {
            return $this->incomplete('GRAPH_NOT_OBSERVED', $exception->getMessage(), [
                $this->assertion($context, 'graph nodes carry provenance', 'nodes with provenance', $exception->getMessage(), 'QueryProjectGraph', 'not_observed'),
            ]);
        }
        $nodes = is_array($result['nodes'] ?? null) ? $result['nodes'] : [];
        if ($nodes === []) {
            return $this->incomplete('GRAPH_NOT_OBSERVED', 'molly:project:query returned no nodes for this project.', [
                $this->assertion($context, 'graph nodes carry provenance', 'nodes with provenance', '0 nodes', 'QueryProjectGraph', 'not_observed'),
            ]);
        }
        $missing = 0;
        foreach ($nodes as $node) {
            if (! is_array($node) || ! is_array($node['provenance'] ?? null) || $node['provenance'] === []) {
                $missing++;
            }
        }
        $assertion = $this->assertion(
            $context,
            'graph nodes carry provenance',
            'every node has provenance',
            $missing === 0 ? count($nodes).' nodes with provenance' : $missing.' nodes without provenance',
            'QueryProjectGraph',
            $missing === 0 ? 'pass' : 'fail',
        );

        return $missing === 0
            ? $this->pass('GRAPH_PROVENANCE', 'The project graph nodes include provenance.', [$assertion])
            : $this->fail('GRAPH_PROVENANCE_MISSING', 'A project graph node has no provenance.', [$assertion]);
    }

    private function glossarySurface(VerificationContext $context): ProbeResult
    {
        $path = $context->projectRoot === '' ? '' : $context->projectRoot.'/.molly/GLOSSARY.md';
        if ($path === '' || ! is_file($path)) {
            return $this->incomplete('GLOSSARY_NOT_OBSERVED', 'Molly has no .molly/GLOSSARY.md to compare.', [
                $this->assertion($context, 'glossary terms match the journal with sources', 'terms with sources', 'file missing', $path, 'not_observed'),
            ]);
        }
        $body = (string) file_get_contents($path);
        $missing = [];
        foreach ($this->glossary->glossaryTerms() as $term) {
            if (! str_contains($body, $term['term'].':')) {
                $missing[] = $term['term'];
            }
        }
        $hasSource = str_contains($body, 'Source:');
        $ok = $missing === [] && $hasSource;
        $assertion = $this->assertion(
            $context,
            'glossary terms match the journal with sources',
            'journal terms with a source',
            $ok ? 'terms present with a source' : ($missing === [] ? 'source missing' : implode(', ', $missing)),
            $path,
            $ok ? 'pass' : 'fail',
        );

        return $ok
            ? $this->pass('GLOSSARY_TERMS_MATCH', 'The glossary file matches the journal terms and records a source.', [$assertion])
            : $this->fail('GLOSSARY_MISMATCH', 'The glossary file does not match the journal terms.', [$assertion]);
    }

    private function settingsMatch(VerificationContext $context): ProbeResult
    {
        $run = $this->run($context);
        if ($run === null) {
            return $this->incomplete('SETTINGS_NOT_OBSERVED', 'Molly has no run effective configuration to compare with displayed settings.', [
                $this->assertion($context, 'displayed model equals run effective model', 'equal models', 'no run', 'GetMollySettings', 'not_observed'),
            ]);
        }
        $shown = $this->runs->handle($run['id']);
        $effective = is_array($shown?->effective_config) ? $shown->effective_config : [];
        $stored = $effective['config']['runtime']['model'] ?? null;
        if (! is_string($stored)) {
            return $this->incomplete('SETTINGS_NOT_OBSERVED', 'The run has no effective model to compare.', [
                $this->assertion($context, 'displayed model equals run effective model', 'a model', 'no effective model', 'database:molly_runs/'.$run['id'], 'not_observed'),
            ]);
        }
        try {
            $displayed = $this->settings->handle()['settings']['runtime']['model'] ?? null;
        } catch (Throwable $exception) {
            return $this->fail('SETTINGS_UNREADABLE', $exception->getMessage(), [
                $this->assertion($context, 'displayed model equals run effective model', $stored, $exception->getMessage(), 'GetMollySettings', 'fail'),
            ]);
        }
        $displayedText = is_string($displayed) ? $displayed : 'null';
        $assertion = $this->assertion(
            $context,
            'displayed model equals run effective model',
            $stored,
            $displayedText,
            'GetMollySettings',
            $displayedText === $stored ? 'pass' : 'fail',
        );

        return $displayedText === $stored
            ? $this->pass('SETTINGS_MATCH', 'Displayed settings match the run effective model.', [$assertion])
            : $this->fail('SETTINGS_MISMATCH', 'Displayed settings do not match the run effective model.', [$assertion]);
    }

    private function worker(VerificationContext $context): ProbeResult
    {
        $task = $this->task($context);
        if (! $task instanceof Task || ! is_string($task->worker_id) || $task->worker_id === '') {
            return $this->incomplete('WORKER_NOT_OBSERVED', 'Molly has no running task with a worker id.', [
                $this->assertion($context, 'worker status is readable', 'worker id and status', 'none', 'ListTasks', 'not_observed'),
            ]);
        }
        $assertion = $this->assertion(
            $context,
            'worker status is readable',
            'worker id and status',
            $task->worker_id.' '.$task->status,
            'database:molly_tasks/'.$task->id,
            'pass',
        );

        return ProbeResult::needsNative(
            'WORKER_UI_NOT_OBSERVED',
            'The worker status is '.$task->status.' for '.$task->worker_id.'. Stopping it from Bloom still needs an Accessibility observation.',
            [$assertion],
        );
    }

    private function trace(VerificationContext $context): ProbeResult
    {
        $task = $this->task($context);
        $run = $this->run($context);
        if (! $task instanceof Task || $run === null) {
            return $this->incomplete('TRACE_NOT_OBSERVED', 'Molly has no task and run chain to compare.', [
                $this->assertion($context, 'task, run, conversation, diff, and receipt share ids', 'one chain', 'incomplete', 'ShowRun', 'not_observed'),
            ]);
        }
        $shown = $this->runs->handle($run['id']);
        $report = is_array($shown?->report) ? $shown->report : [];
        $diff = is_array($report['diff'] ?? null) ? $report['diff'] : [];
        $receipt = is_array($report['receipt'] ?? null) ? $report['receipt'] : [];
        $listed = $this->conversations->handle($task->id);
        $conversation = $listed['conversations'][0] ?? null;
        $expected = $task->id.' '.$run['id'];
        $actualParts = [
            'task '.$task->id,
            'run '.$run['id'],
            'conversation '.(is_array($conversation) && in_array($task->id, $conversation['task_ids'] ?? [], true) && in_array($run['id'], $conversation['run_ids'] ?? [], true) ? 'linked' : 'missing'),
            'diff '.(($diff['task_id'] ?? null) === $task->id && ($diff['run_id'] ?? null) === $run['id'] ? 'linked' : 'missing'),
            'receipt '.(($receipt['run_id'] ?? null) === $run['id'] ? 'linked' : 'missing'),
        ];
        $ok = ! str_contains(implode(' ', $actualParts), 'missing');
        if (! is_array($conversation) && $diff === [] && $receipt === []) {
            return $this->incomplete('TRACE_NOT_OBSERVED', 'The task has no conversation, diff, and receipt to compare.', [
                $this->assertion($context, 'task, run, conversation, diff, and receipt share ids', $expected, implode('; ', $actualParts), 'ShowRun', 'not_observed'),
            ]);
        }
        $assertion = $this->assertion(
            $context,
            'task, run, conversation, diff, and receipt share ids',
            $expected,
            implode('; ', $actualParts),
            'database:molly_runs/'.$run['id'],
            $ok ? 'pass' : 'fail',
        );

        return $ok
            ? $this->pass('TRACE_IDS_MATCH', 'The task, run, conversation, diff, and receipt use the same ids.', [$assertion])
            : $this->fail('TRACE_IDS_DIVERGE', 'The traceability chain does not resolve to the same ids.', [$assertion]);
    }

    private function cliVisible(VerificationContext $context): ProbeResult
    {
        $task = $this->task($context);
        if (! $task instanceof Task) {
            return $this->incomplete('CLI_TO_BLOOM_STATE_NOT_OBSERVED', 'Molly has no CLI task for Bloom to show.', [
                $this->assertion($context, 'CLI task is in the list Bloom reads', 'task id', 'none', 'ListTasks', 'not_observed'),
            ]);
        }

        return ProbeResult::needsNative(
            'CLI_BLOOM_UI_NOT_OBSERVED',
            'The CLI task is in the list Bloom reads. A screenshot of that row still needs Screen Recording.',
            [$this->assertion($context, 'CLI task is in the list Bloom reads', $task->id, $task->id, 'database:molly_tasks', 'pass')],
        );
    }

    private function hostLog(VerificationContext $context, string $name, string $code, string ...$needles): ProbeResult
    {
        $path = $context->projectRoot === '' ? '' : $context->projectRoot.'/'.$name;
        if ($path === '' || ! is_file($path)) {
            return $this->incomplete($code.'_NOT_OBSERVED', 'Molly has no '.$name.' from the compiled host.', [
                $this->assertion($context, $name.' records the host result', implode(' ', $needles), 'file missing', $path, 'not_observed'),
            ]);
        }
        $body = (string) file_get_contents($path);
        $missing = array_values(array_filter($needles, fn (string $needle): bool => ! str_contains($body, $needle)));
        $assertion = $this->assertion(
            $context,
            $name.' records the host result',
            implode(' ', $needles),
            $missing === [] ? 'present' : 'missing '.implode(' ', $missing),
            $path,
            $missing === [] ? 'pass' : 'fail',
        );

        return $missing === []
            ? $this->pass($code.'_RECORDED', $name.' contains the expected host record.', [$assertion])
            : $this->fail($code.'_UNHELPFUL', $name.' does not contain the expected host record.', [$assertion]);
    }

    private function missingHost(VerificationContext $context): ProbeResult
    {
        $script = PackageRoot::path().'/bin/molly-bloom-plugin-register';
        $missing = rtrim($context->evidenceRoot !== '' ? $context->evidenceRoot : sys_get_temp_dir(), '/').'/bloom-app-missing';
        if (! is_file($script)) {
            return $this->fail('PLUGIN_INSTALL_ENTRY_MISSING', 'bin/molly-bloom-plugin-register is missing.', [
                $this->assertion($context, 'missing Bloom produces a clear install error', 'exit 1 and a clear error', 'script missing', $script, 'fail'),
            ]);
        }
        $result = Process::timeout(20)->env([
            'BLOOM_APP' => $missing,
            'HOME' => sys_get_temp_dir(),
        ])->run(['bash', $script]);
        $output = trim($result->errorOutput()."\n".$result->output());
        $clear = $result->exitCode() === 1 && str_contains($output, 'no app exists');
        $assertion = $this->assertion(
            $context,
            'missing Bloom produces a clear install error',
            'exit 1 and a clear error',
            'exit '.$result->exitCode().' '.$output,
            $script,
            $clear ? 'pass' : 'fail',
        );

        return $clear
            ? $this->pass('MISSING_HOST_CLEAR', 'The plugin install entry point reported that Bloom is not at the given path.', [$assertion])
            : $this->fail('MISSING_HOST_UNCLEAR', 'The plugin install entry point did not report a clear missing-host error.', [$assertion]);
    }

    private function brokenAssets(VerificationContext $context): ProbeResult
    {
        return $this->hostLog($context, 'host-broken.log', 'BROKEN_ASSETS', 'missingBundle', 'missingProvider');
    }

    private function restart(VerificationContext $context): ProbeResult
    {
        $path = $context->projectRoot === '' ? '' : $context->projectRoot.'/.molly/restart-state.json';
        if ($path === '' || ! is_file($path)) {
            return $this->incomplete('RESTART_PERSISTENCE_NOT_OBSERVED', 'Molly has no restart persistence record for this project.', [
                $this->assertion($context, 'task id survives restart', 'same task id', 'file missing', $path, 'not_observed'),
            ]);
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->fail('RESTART_RECORD_INVALID', $path.' is not valid JSON.', [
                $this->assertion($context, 'task id survives restart', 'same task id', 'invalid JSON', $path, 'fail'),
            ]);
        }
        $before = is_array($decoded) ? (string) ($decoded['before']['task_id'] ?? '') : '';
        $after = is_array($decoded) ? (string) ($decoded['after']['task_id'] ?? '') : '';
        if ($before === '' || $after === '') {
            return $this->incomplete('RESTART_PERSISTENCE_NOT_OBSERVED', 'The restart record has no before and after task id.', [
                $this->assertion($context, 'task id survives restart', 'same task id', 'incomplete record', $path, 'not_observed'),
            ]);
        }
        $assertion = $this->assertion($context, 'task id survives restart', $before, $after, $path, $before === $after ? 'pass' : 'fail');
        if ($before !== $after) {
            return $this->fail('RESTART_STATE_LOST', 'The task id after restart does not match the id recorded before it.', [$assertion]);
        }

        return ProbeResult::needsNative(
            'RESTART_UI_NOT_OBSERVED',
            'The persisted task id matches. A compiled-host observation of that state still needs Screen Recording.',
            [$assertion],
        );
    }

    private function seam(VerificationContext $context): CheckAssertion
    {
        $root = $context->repoRoot;
        $manifest = $root.'/bloom-plugin/plugin.json';
        $provider = $root.'/bloom-plugin/Surfaces/Sources/MollySurfaces/MollySurfaceProvider.swift';
        if (! is_file($manifest) || ! is_file($provider)) {
            return $this->assertion($context, 'plugin nav matches the surface provider', 'sifrious.molly apiVersion 1', 'manifest or provider missing', $manifest, 'fail');
        }
        try {
            $json = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->assertion($context, 'plugin nav matches the surface provider', 'valid plugin.json', 'invalid JSON', $manifest, 'fail');
        }
        if (! is_array($json) || ($json['id'] ?? null) !== 'sifrious.molly' || ($json['apiVersion'] ?? null) !== 1) {
            return $this->assertion($context, 'plugin nav matches the surface provider', 'sifrious.molly apiVersion 1', 'unexpected manifest', $manifest, 'fail');
        }
        $nav = array_map(fn (mixed $item): string => is_array($item) ? (string) ($item['id'] ?? '') : '', $json['nav'] ?? []);
        preg_match_all('/\("([a-z0-9.]+)",/', (string) file_get_contents($provider), $matches);
        $registered = $matches[1];
        $match = $nav !== [] && $nav === $registered;

        return $this->assertion(
            $context,
            'plugin nav matches the surface provider',
            implode(',', $nav),
            implode(',', $registered),
            $manifest,
            $match ? 'pass' : 'fail',
        );
    }

    private function telemetryPath(VerificationContext $context): ?string
    {
        if (is_string($context->hostTelemetryPath) && is_file($context->hostTelemetryPath)) {
            return $context->hostTelemetryPath;
        }
        $candidate = $context->projectRoot === '' ? '' : $context->projectRoot.'/host-telemetry.json';

        return $candidate !== '' && is_file($candidate) ? $candidate : null;
    }

    private function task(VerificationContext $context): Task|string|null
    {
        if ($context->projectRoot === '') {
            return null;
        }
        try {
            $listed = $this->tasks->handle(100);
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }
        foreach ($listed as $task) {
            if ($task->workspace === $context->projectRoot) {
                return $task;
            }
        }

        return null;
    }

    /** @return array{id: string}|null */
    private function run(VerificationContext $context): ?array
    {
        $task = $this->task($context);
        if (! $task instanceof Task) {
            return null;
        }
        $run = $task->runs()->first();
        if ($run === null) {
            return null;
        }

        return ['id' => $run->id];
    }

    private function assertion(
        VerificationContext $context,
        string $assertion,
        string $expected,
        string $actual,
        string $source,
        string $result,
    ): CheckAssertion {
        return new CheckAssertion($context->candidateSha, $context->runId, $assertion, $expected, $actual, $source, $result);
    }

    /** @param  list<CheckAssertion>  $assertions */
    private function pass(string $reason, string $message, array $assertions): ProbeResult
    {
        return ProbeResult::complete(CheckOutcome::Pass, $reason, $message, $this->sources($assertions), $assertions);
    }

    /** @param  list<CheckAssertion>  $assertions */
    private function fail(string $reason, string $message, array $assertions): ProbeResult
    {
        return ProbeResult::complete(CheckOutcome::ProductFail, $reason, $message, $this->sources($assertions), $assertions);
    }

    /** @param  list<CheckAssertion>  $assertions */
    private function incomplete(string $reason, string $message, array $assertions): ProbeResult
    {
        return ProbeResult::complete(CheckOutcome::EvidenceIncomplete, $reason, $message, $this->sources($assertions), $assertions);
    }

    /**
     * @param  list<CheckAssertion>  $assertions
     * @return list<string>
     */
    private function sources(array $assertions): array
    {
        return array_values(array_filter(array_map(
            fn (CheckAssertion $assertion): string => $assertion->sourceArtifact,
            $assertions,
        )));
    }
}
