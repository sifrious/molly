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
 * Runs the M04 assertions that can be read from services, storage, host
 * logs, and a Bloom process that is already running. A remaining Bloom UI
 * observation stays needs_native. A caller-supplied PASS record is ignored.
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
        private QueueWorkerProbe $workerProbe,
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
            'M04.13' => $this->hostDiagnostic($context, 'host-incompat.log', 'INCOMPAT_LOG', 'incompatible'),
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
        $host = $context->host;
        $process = $this->assertion(
            $context,
            'compiled Bloom process is running',
            'bloom process',
            $host?->running === true ? 'pid '.$host->pid.' '.$host->command : 'no bloom process',
            $host?->processSource ?? 'ps -ax -o pid=,command=',
            $host?->running === true ? 'pass' : 'not_observed',
        );
        if ($host === null || ! $host->running) {
            return $this->prerequisite(
                'COMPILED_HOST_NOT_OBSERVED',
                $host?->ambiguous === true
                    ? 'More than one Bloom process is running. Set BLOOM_APP to the app under test. M04.1 did not take the first match.'
                    : 'The extension seam matches. The compiled Bloom host has not been observed, so M04.1 is not accepted.',
                [$seam, $process],
            );
        }
        $discovered = $host->pluginId === 'sifrious.molly' && $host->apiVersion === 1 && $host->enabled;
        $plugin = $this->assertion(
            $context,
            'running host has sifrious.molly enabled at apiVersion 1 and the plugin loaded',
            'sifrious.molly apiVersion 1 enabled and loaded',
            'plugin '.($host->pluginId ?? 'missing').' apiVersion '.($host->apiVersion === null ? 'missing' : (string) $host->apiVersion).' enabled '.($host->enabled ? 'yes' : 'no').' loaded '.($host->loaded ? 'yes' : 'no'),
            $host->loaded ? $host->loadSource : $host->pluginSource,
            $discovered && $host->loaded ? 'pass' : ($discovered ? 'not_observed' : 'fail'),
        );
        if (! $discovered) {
            return $this->fail('HOST_PLUGIN_NOT_DISCOVERED', 'Bloom is running as pid '.$host->pid.'. The enabled plugin is not sifrious.molly at apiVersion 1.', [$seam, $process, $plugin]);
        }
        if (! $host->loaded) {
            return $this->incomplete('PLUGIN_LOAD_NOT_OBSERVED', 'Bloom is running as pid '.$host->pid.' and sifrious.molly is enabled on disk. Molly did not observe MollySurfaces loaded in that process.', [$seam, $process, $plugin]);
        }

        return $this->pass('HOST_PLUGIN_DISCOVERED', 'Bloom is running as pid '.$host->pid.' and sifrious.molly apiVersion 1 is loaded.', [$seam, $process, $plugin]);
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
            return $this->taskMissing($context, $task, 'a task is listed for the project', 'one task', 'ListTasks');
        }

        return $this->pass('TASK_LISTED', 'molly:tasks lists '.$task->reference().'.', [
            $this->assertion($context, 'a task is listed for the project', 'one task', $task->id, 'database:molly_tasks', 'pass'),
        ]);
    }

    private function runSurface(VerificationContext $context): ProbeResult
    {
        $run = $this->run($context);
        if (! isset($run['id'])) {
            return $this->runMissing($context, $run, 'molly:show returns run status and verifier results', 'status and verification', 'ShowRun');
        }
        $shown = $this->runs->handle($run['id']);
        $report = is_array($shown?->report) ? $shown->report : [];
        $status = is_string($shown?->status) ? $shown->status : '';
        $verification = $this->verifierRecord($report);
        $ok = $status !== '' && $verification !== null;
        $assertion = $this->assertion(
            $context,
            'molly:show returns run status and verifier results',
            'status and verification',
            $ok ? $this->verifierActual($status, $verification) : 'missing status or verification',
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
            return $this->taskMissing($context, $task, 'conversation links to the task', 'task id', 'ListConversations');
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
        $task = $this->task($context);
        if (! $task instanceof Task) {
            return $this->taskMissing($context, $task, 'the selected task is in the project graph', 'task id with provenance', 'QueryProjectGraph');
        }
        try {
            $result = $this->graph->handle($task->id, $context->projectRoot, 1, 20);
        } catch (Throwable $exception) {
            return $this->incomplete('GRAPH_NOT_OBSERVED', $exception->getMessage(), [
                $this->assertion($context, 'the selected task is in the project graph', $task->id, $exception->getMessage(), 'QueryProjectGraph', 'not_observed'),
            ]);
        }
        $nodes = is_array($result['nodes'] ?? null) ? $result['nodes'] : [];
        $exact = false;
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $key = strtolower((string) ($node['key'] ?? ''));
            $label = strtolower((string) ($node['label'] ?? ''));
            if ($key === strtolower($task->id) || $label === strtolower($task->id)) {
                $exact = true;
            }
        }
        if ($nodes === [] || ! $exact) {
            return $this->incomplete('GRAPH_NOT_OBSERVED', 'molly:project:query returned no node for task '.$task->id.'.', [
                $this->assertion($context, 'the selected task is in the project graph', $task->id, '0 nodes for this task', 'QueryProjectGraph', 'not_observed'),
            ]);
        }
        $missing = 0;
        foreach ($nodes as $node) {
            if (! is_array($node) || ! $this->hasProvenance($node)) {
                $missing++;
            }
        }
        $database = (string) config('molly.knowledge.database', '.molly/knowledge.sqlite');
        $assertion = $this->assertion(
            $context,
            'the selected task is in the project graph with provenance',
            $task->id.' with provenance',
            $missing === 0 ? count($nodes).' nodes for '.$task->id.' with provenance' : $missing.' nodes without provenance',
            $database,
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
        if (! isset($run['id'])) {
            return $this->runMissing($context, $run, 'displayed model equals run effective model', 'equal models', 'GetMollySettings');
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
        if ($context->projectRoot === '') {
            return $this->incomplete('WORKER_NOT_OBSERVED', 'Molly has no project whose queue worker can be read.', [
                $this->assertion($context, 'molly:worker queue:work process is running', 'running queue:work', 'no project', 'ManageWorker', 'not_observed'),
            ]);
        }
        try {
            $status = $this->workerProbe->status($context->projectRoot);
        } catch (Throwable $exception) {
            $invalid = str_contains($exception->getMessage(), 'WORKER_RECORD_INVALID');

            return $invalid
                ? $this->fail('WORKER_RECORD_INVALID', $exception->getMessage(), [
                    $this->assertion($context, 'molly:worker queue:work process is running', 'running queue:work', $exception->getMessage(), 'ManageWorker', 'fail'),
                ])
                : $this->incomplete('WORKER_NOT_OBSERVED', $exception->getMessage(), [
                    $this->assertion($context, 'molly:worker queue:work process is running', 'running queue:work', $exception->getMessage(), 'ManageWorker', 'not_observed'),
                ]);
        }
        $state = is_string($status['state'] ?? null) ? $status['state'] : 'unreadable';
        $command = is_array($status['command'] ?? null) ? implode(' ', $status['command']) : '';
        $source = is_string($status['pid_file'] ?? null) ? $status['pid_file'] : 'ManageWorker';
        $queueWorker = $state === 'running' && str_contains($command, 'queue:work');
        $host = $context->host;
        $assertion = $this->assertion(
            $context,
            'molly:worker queue:work process is running',
            'running queue:work',
            'control '.$state.' command '.($command === '' ? 'none' : $command).($host?->running === true ? '; bloom pid '.$host->pid : '; no bloom process'),
            $source,
            $queueWorker ? 'pass' : 'not_observed',
        );
        if (! $queueWorker) {
            return $this->incomplete('WORKER_NOT_RUNNING', 'molly:worker is '.$state.'. A stopped or stale worker, or a process that is not queue:work, does not pass M04.10.', [$assertion]);
        }
        if ($host === null || ! $host->running) {
            return $this->prerequisite(
                'WORKER_HOST_NOT_OBSERVED',
                'queue:work is running. The compiled Bloom host is not running, so M04.10 is not accepted.',
                [$assertion],
            );
        }

        return $this->pass('WORKER_STATUS_READ', 'Bloom is running and molly:worker is queue:work as pid '.(string) ($status['pid'] ?? '').'.', [$assertion]);
    }

    private function trace(VerificationContext $context): ProbeResult
    {
        $task = $this->task($context);
        $run = $this->run($context);
        if (! $task instanceof Task || ! isset($run['id'])) {
            return $this->incomplete('TRACE_NOT_OBSERVED', 'Molly has no task and run chain to compare.', [
                $this->assertion($context, 'task, run, conversation, diff, and receipt share ids', 'one chain', is_string($task) ? $task : (is_string($run['problem'] ?? null) ? $run['problem'] : 'incomplete'), 'ShowRun', 'not_observed'),
            ]);
        }
        $shown = $this->runs->handle($run['id']);
        $report = is_array($shown?->report) ? $shown->report : [];
        $listed = $this->conversations->handle($task->id);
        $conversation = $listed['conversations'][0] ?? null;
        $conversationLinked = is_array($conversation)
            && in_array($task->id, $conversation['task_ids'] ?? [], true)
            && in_array($run['id'], $conversation['run_ids'] ?? [], true);
        [$diff, $receipt] = $this->traceRecords($report, $task->id, $run['id'], $conversationLinked);
        $expected = $task->id.' '.$run['id'];
        $actualParts = [
            'task '.$task->id,
            'run '.$run['id'],
            'conversation '.($conversationLinked ? 'linked' : 'missing'),
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

    /**
     * The verifier result molly:show can return. A stop before Pest stores
     * that fact on verification, or on verification_outcomes when an older
     * run saved the outcome without copying it onto verification.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>|null
     */
    private function verifierRecord(array $report): ?array
    {
        $verification = $report['verification'] ?? null;
        if (is_array($verification) && $this->verifierFact($verification)) {
            return $verification;
        }
        $state = $report['verification_outcomes']['pest']['state'] ?? null;
        if (! is_string($state) || $state === '') {
            return null;
        }
        $record = ['status' => strtolower($state)];
        if (is_string($report['error'] ?? null) && preg_match('/\A([A-Z][A-Z0-9_]+):/', $report['error'], $match) === 1) {
            $record['reason'] = $match[1];
        }

        return $record;
    }

    /** @param  array<string, mixed>  $verification */
    private function verifierFact(array $verification): bool
    {
        return isset($verification['status']) || isset($verification['reason']) || array_key_exists('passed', $verification) || array_key_exists('failed', $verification);
    }

    /** @param  array<string, mixed>  $verification */
    private function verifierActual(string $status, array $verification): string
    {
        $shown = is_string($verification['status'] ?? null) ? $verification['status'] : 'recorded';
        $reason = is_string($verification['reason'] ?? null) && $verification['reason'] !== '' ? ' '.$verification['reason'] : '';

        return $status.' verification '.$shown.$reason;
    }

    /**
     * Diff and receipt rows the trace compares. A stored row wins. When the
     * conversation is already linked and the run stopped before a patch, the
     * missing diff is that run's not_produced record, and the receipt is the
     * pest receipt already saved for the run.
     *
     * @param  array<string, mixed>  $report
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function traceRecords(array $report, string $taskId, string $runId, bool $conversationLinked): array
    {
        $diff = is_array($report['diff'] ?? null) ? $report['diff'] : [];
        $receipt = is_array($report['receipt'] ?? null) ? $report['receipt'] : [];
        if (! $conversationLinked) {
            return [$diff, $receipt];
        }
        if ($diff === [] && $this->patchNotProduced($report)) {
            $diff = [
                'task_id' => $taskId,
                'run_id' => $runId,
                'status' => 'not_produced',
            ];
        }
        if ($receipt === []) {
            foreach ($report['verification_receipts'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $path = is_string($item['path'] ?? null) ? $item['path'] : '';
                if (($item['verifier'] ?? null) === 'pest' || ($path !== '' && str_contains($path, $runId))) {
                    $receipt = [
                        'id' => is_string($item['evidence_digest'] ?? null) ? $item['evidence_digest'] : $runId,
                        'task_id' => $taskId,
                        'run_id' => $runId,
                    ];
                    break;
                }
            }
        }

        return [$diff, $receipt];
    }

    /** @param  array<string, mixed>  $report */
    private function patchNotProduced(array $report): bool
    {
        $captured = $report['execution_target']['diff']['status'] ?? null;
        if ($captured === 'captured') {
            return false;
        }
        $changes = $report['changes'] ?? null;

        return (! is_array($changes) || $changes === [])
            && (is_string($report['error'] ?? null) || ($report['terminated_before_completion'] ?? false) === true);
    }

    private function cliVisible(VerificationContext $context): ProbeResult
    {
        $task = $this->task($context);
        if (! $task instanceof Task) {
            return $this->taskMissing($context, $task, 'CLI task is in the list Bloom reads', 'task id', 'ListTasks');
        }

        return ProbeResult::needsNative(
            'CLI_BLOOM_UI_NOT_OBSERVED',
            'The CLI task is in the list Bloom reads. A screenshot of that row still needs Screen Recording.',
            [$this->assertion($context, 'CLI task is in the list Bloom reads', $task->id, $task->id, 'database:molly_tasks', 'pass')],
        );
    }

    private function hostDiagnostic(VerificationContext $context, string $name, string $code, string $kind): ProbeResult
    {
        $path = $context->projectRoot === '' ? '' : $context->projectRoot.'/'.$name;
        $record = $this->attributedRecord($path, $context->candidateSha);
        if ($record === null) {
            return $this->incomplete($code.'_NOT_OBSERVED', $name.' has no host record with process, host, plugin, candidate, and timestamp. Molly did not treat the file as a product failure.', [
                $this->assertion($context, $name.' attributes a host diagnostic', 'provenance and a '.$kind.' diagnostic', is_file($path) ? 'provenance missing' : 'file missing', $path, 'not_observed'),
            ]);
        }
        $signal = $kind === 'incompatible'
            ? (($record['loaded'] ?? null) === false || (is_int($record['api_version'] ?? null) && $record['api_version'] !== 1))
            : (($record['missing_bundle'] ?? null) === true || ($record['missing_provider'] ?? null) === true);
        $assertion = $this->assertion(
            $context,
            $name.' attributes a host diagnostic',
            'provenance and a '.$kind.' diagnostic',
            $signal ? 'recorded' : 'no '.$kind.' diagnostic',
            $path,
            $signal ? 'pass' : 'not_observed',
        );
        if (! $signal || ($record['plugin'] ?? null) !== 'sifrious.molly') {
            return $this->incomplete($code.'_NOT_OBSERVED', $name.' does not record a '.$kind.' diagnostic for sifrious.molly. An honest host log without that fact stays incomplete.', [$assertion]);
        }
        $host = $context->host;
        $pid = $record['process']['pid'] ?? null;
        $process = $this->assertion(
            $context,
            'compiled Bloom process is the recorded host',
            'bloom pid '.$pid,
            $host?->running === true ? 'pid '.$host->pid : 'no bloom process',
            $host?->processSource ?? 'ps -ax -o pid=,command=',
            $host?->running === true && $host->pid === $pid ? 'pass' : 'not_observed',
        );
        if ($host === null || ! $host->running || $host->pid !== $pid) {
            return $this->prerequisite(
                'HOST_DIAGNOSTIC_NOT_OBSERVED',
                $name.' records the diagnostic. The compiled Bloom process in that record was not observed, so it is not accepted.',
                [$assertion, $process],
            );
        }

        return $this->pass($code.'_RECORDED', $name.' attributes the host diagnostic to the running Bloom process.', [$assertion, $process]);
    }

    /** @return array<string, mixed>|null */
    private function attributedRecord(string $path, string $candidate): ?array
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($decoded)) {
            return null;
        }
        $pid = $decoded['process']['pid'] ?? null;
        $host = $decoded['host'] ?? null;
        $plugin = $decoded['plugin'] ?? null;
        $recorded = $decoded['candidate'] ?? null;
        $at = $decoded['observed_at'] ?? null;
        if (! is_int($pid) || $pid < 2 || ! is_string($host) || $host === '' || ! is_string($plugin) || $plugin === '') {
            return null;
        }
        if (! is_string($recorded) || preg_match('/\A[0-9a-f]{40}\z/', $recorded) !== 1 || $recorded !== $candidate) {
            return null;
        }
        if (! is_string($at) || $at === '') {
            return null;
        }

        return $decoded;
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
        return $this->hostDiagnostic($context, 'host-broken.log', 'BROKEN_ASSETS', 'broken');
    }

    private function restart(VerificationContext $context): ProbeResult
    {
        $path = $context->projectRoot === '' ? '' : $context->projectRoot.'/.molly/restart-state.json';
        $record = $this->attributedRecord($path, $context->candidateSha);
        $before = $this->restartSide(is_array($record) ? ($record['before'] ?? null) : null);
        $after = $this->restartSide(is_array($record) ? ($record['after'] ?? null) : null);
        $independent = $before !== null && $after !== null
            && $before['source'] !== $after['source']
            && $before['pid'] !== $after['pid']
            && $before['observed_at'] !== $after['observed_at'];
        if ($record === null || ! $independent) {
            return $this->incomplete('RESTART_PERSISTENCE_NOT_OBSERVED', 'Molly has no independent before and after observations for this restart. A matching task id or a pid change is not that record.', [
                $this->assertion($context, 'task id survives an observed restart', 'plugin and cli observations with different pids', 'provenance missing', $path, 'not_observed'),
            ]);
        }
        $kept = $before['task_id'] === $after['task_id'];
        $assertion = $this->assertion(
            $context,
            'task id survives an observed restart',
            $before['task_id'].' pid '.$before['pid'].' '.$before['source'],
            $after['task_id'].' pid '.$after['pid'].' '.$after['source'],
            $path,
            $kept ? 'pass' : 'fail',
        );
        if (! $kept) {
            return $this->fail('RESTART_STATE_LOST', 'The task id observed after restart does not match the id observed before it.', [$assertion]);
        }
        $host = $context->host;
        $process = $this->assertion(
            $context,
            'compiled Bloom process is the restarted host',
            'bloom pid '.$after['pid'],
            $host?->running === true ? 'pid '.$host->pid : 'no bloom process',
            $host?->processSource ?? 'ps -ax -o pid=,command=',
            $host?->running === true && $host->pid === $after['pid'] ? 'pass' : 'not_observed',
        );
        if ($host === null || ! $host->running || $host->pid !== $after['pid']) {
            return $this->prerequisite(
                'RESTART_HOST_NOT_OBSERVED',
                'The before and after observations agree. The restarted Bloom process was not observed, so M04.16 is not accepted.',
                [$assertion, $process],
            );
        }

        return $this->pass('RESTART_STATE_KEPT', 'Bloom is running as the pid observed after restart, and the task id matches the observation from before it.', [$assertion, $process]);
    }

    /** @return array{task_id: string, pid: int, source: string, observed_at: string}|null */
    private function restartSide(mixed $side): ?array
    {
        if (! is_array($side)) {
            return null;
        }
        $task = $side['task_id'] ?? null;
        $pid = $side['pid'] ?? null;
        $source = $side['source'] ?? null;
        $at = $side['observed_at'] ?? null;
        if (! is_string($task) || $task === '' || ! is_int($pid) || $pid < 2) {
            return null;
        }
        if (! in_array($source, ['plugin', 'cli'], true) || ! is_string($at) || $at === '') {
            return null;
        }

        return ['task_id' => $task, 'pid' => $pid, 'source' => $source, 'observed_at' => $at];
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

    /** @param  array<string, mixed>  $node */
    private function hasProvenance(array $node): bool
    {
        $sources = $node['sources'] ?? null;
        $provenance = $node['provenance'] ?? null;

        return (is_array($sources) && $sources !== []) || (is_array($provenance) && $provenance !== []);
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
        $inProject = [];
        foreach ($listed as $task) {
            if ($task->workspace === $context->projectRoot) {
                $inProject[] = $task;
            }
        }
        $selected = $context->taskReference;
        if (is_string($selected) && $selected !== '') {
            foreach ($inProject as $task) {
                if ($task->id === $selected || $task->nickname === $selected) {
                    return $task;
                }
            }

            return 'TASK_NOT_OBSERVED';
        }
        if (count($inProject) === 1) {
            return $inProject[0];
        }
        if (count($inProject) > 1) {
            return 'TASK_NOT_SELECTED';
        }

        return null;
    }

    /** @return array{id: string}|array{problem: string} */
    private function run(VerificationContext $context): array
    {
        $task = $this->task($context);
        if (! $task instanceof Task) {
            return ['problem' => is_string($task) ? $task : 'RUN_NOT_OBSERVED'];
        }
        $runs = $task->runs()->get();
        $selected = $context->runReference;
        if (is_string($selected) && $selected !== '') {
            foreach ($runs as $run) {
                if ($run->id === $selected) {
                    return ['id' => $run->id];
                }
            }

            return ['problem' => 'RUN_NOT_OBSERVED'];
        }
        if ($runs->count() === 1) {
            return ['id' => $runs->first()->id];
        }
        if ($runs->count() > 1) {
            return ['problem' => 'RUN_NOT_SELECTED'];
        }

        return ['problem' => 'RUN_NOT_OBSERVED'];
    }

    private function taskMissing(VerificationContext $context, Task|string|null $task, string $assertion, string $expected, string $source): ProbeResult
    {
        $reason = $task === 'TASK_NOT_SELECTED' ? 'TASK_NOT_SELECTED' : 'TASK_NOT_OBSERVED';

        return $this->incomplete($reason, $reason === 'TASK_NOT_SELECTED'
            ? 'This project has more than one task. Pass --task with the id or nickname.'
            : 'molly:tasks has no selected task for this project.', [
                $this->assertion($context, $assertion, $expected, is_string($task) ? $task : 'none', $source, 'not_observed'),
            ]);
    }

    /** @param  array{problem?: string}|null  $run */
    private function runMissing(VerificationContext $context, ?array $run, string $assertion, string $expected, string $source): ProbeResult
    {
        $problem = is_string($run['problem'] ?? null) ? $run['problem'] : 'RUN_NOT_OBSERVED';
        $reason = $problem === 'RUN_NOT_SELECTED' ? 'RUN_NOT_SELECTED' : 'RUN_NOT_OBSERVED';

        return $this->incomplete($reason, $reason === 'RUN_NOT_SELECTED'
            ? 'This task has more than one run. Pass --run with the run id.'
            : 'Molly has no saved run to show for this project.', [
                $this->assertion($context, $assertion, $expected, $problem, $source, 'not_observed'),
            ]);
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

    /** @param  list<CheckAssertion>  $assertions */
    private function prerequisite(string $reason, string $message, array $assertions): ProbeResult
    {
        return ProbeResult::complete(CheckOutcome::BlockedPrerequisite, $reason, $message, $this->sources($assertions), $assertions);
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
