<?php

namespace Sifrious\Molly\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use RuntimeException;
use Sifrious\Molly\Actions\ComposePullRequestBody;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\CreateTaskFromPlan;
use Sifrious\Molly\Actions\ImportGitHubIssue;
use Sifrious\Molly\Actions\InspectTask;
use Sifrious\Molly\Actions\LinkTaskThread;
use Sifrious\Molly\Actions\ListTasks;
use Sifrious\Molly\Actions\NameTask;
use Sifrious\Molly\Actions\QueueTask;
use Sifrious\Molly\Actions\RecommendTaskNextStep;
use Sifrious\Molly\Actions\ShowRun;
use Sifrious\Molly\Actions\ShowTask;
use Sifrious\Molly\Actions\StopTask;
use Sifrious\Molly\Contracts\ExecutionTargetRequest;
use Sifrious\Molly\Execution\LocalOrbProvider;

#[Name('molly_task')]
#[Description('Save bounded tasks, read evidence, name tasks, record an Amp thread link, import a GitHub issue, request stop, or queue start/retry. create/from_plan/import_github require workspace and test_path; paths lists optional additional files. Task operations accept nicknames or UUIDs. Creating or linking a task does not execute code. start/retry require a background queue and worker; queued=true means requested, not running or passed. start/retry accept orb, orb_runtime, or orb_model to queue the task on a registered local Orb, which runs it in the task\'s own Git worktree with that Orb\'s model. import_github reads GitHub using the host gh login. approve, lock_test, pr_opened, merged, comment, and handoff record a human decision, so molly_task refuses them with HUMAN_APPROVAL_REQUIRED, changes nothing, and returns the php artisan command a person runs instead. pr_body prints a pull request description; it does not open or merge a pull request or create a worktree.')]
#[IsDestructive]
class MollyTask extends Tool
{
    /**
     * Operations that record a human decision. MCP callers are agents, so molly_task refuses
     * them and names the Artisan command a person runs instead.
     *
     * @var array<string, string>
     */
    private const HUMAN_DECISIONS = [
        'approve' => 'Only a person can approve a verified change.',
        'lock_test' => 'Only a person can lock the Pest test as the acceptance test.',
        'pr_opened' => 'Only a person can record an opened pull request.',
        'merged' => 'Only a person can record a merge.',
        'comment' => 'Only a person can approve posting a GitHub issue comment.',
        'handoff' => 'Only a person can hand a task to another workspace.',
    ];

    /** @var list<string> */
    private const OPERATIONS = [
        'list', 'show', 'create', 'from_plan', 'start', 'retry', 'stop', 'show_run', 'import_github',
        'comment', 'approve', 'lock_test', 'pr_body', 'pr_opened', 'merged', 'handoff', 'name', 'link_thread', 'advice',
    ];

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'operation' => ['required', 'in:'.implode(',', self::OPERATIONS)],
            'id' => ['required_if:operation,show,start,retry,stop,show_run,comment,approve,lock_test,pr_body,pr_opened,merged,handoff,name,link_thread,advice', 'string', 'max:100'],
            'nickname' => ['required_if:operation,name', 'string', 'max:64'],
            'thread' => ['required_if:operation,link_thread', 'string', 'max:38'],
            'plan_id' => ['required_if:operation,from_plan', 'string', 'max:100'],
            'prompt' => ['required_if:operation,create,from_plan', 'string', 'max:8192'],
            'workspace' => ['required_if:operation,create,from_plan,import_github', 'string', 'max:4096'],
            'paths' => ['sometimes', 'array', 'list', 'max:100'],
            'paths.*' => ['required', 'string', 'max:4096'],
            'test_path' => ['required_if:operation,create,from_plan,import_github', 'string', 'max:4096'],
            'allow_test_edits' => ['sometimes', 'boolean'],
            'issue_url' => ['required_if:operation,import_github', 'string', 'max:2048'],
            'reason' => ['sometimes', 'string', 'max:512'],
            'close' => ['sometimes', 'boolean'],
            'url' => ['sometimes', 'string', 'max:2048'],
            'sha' => ['sometimes', 'string', 'max:40'],
            'from_workspace_id' => ['sometimes', 'uuid'],
            'to_workspace_id' => ['sometimes', 'uuid'],
            'next_action' => ['sometimes', 'string', 'max:64'],
            'context' => ['sometimes', 'string', 'max:8192'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'orb' => ['sometimes', 'string', 'max:100'],
            'orb_runtime' => ['sometimes', 'string', 'in:ollama,amp'],
            'orb_model' => ['sometimes', 'string', 'max:200'],
        ]);
        try {
            $result = match ($data['operation']) {
                'list', 'show', 'show_run' => $this->dispatchRead($data),
                'create', 'from_plan', 'import_github' => $this->dispatchCreate($data),
                'approve', 'lock_test', 'pr_opened', 'merged', 'comment', 'handoff' => $this->refuseHumanDecision($data),
                'pr_body', 'stop' => $this->dispatchLifecycle($data),
                'name', 'link_thread' => $this->dispatchLinks($data),
                'advice' => $this->dispatchAdvice($data),
                'start', 'retry' => $this->dispatchQueue($data),
            };

            return Response::structured($result);
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function dispatchRead(array $data): array
    {
        return match ($data['operation']) {
            'list' => ['tasks' => app(ListTasks::class)->handle($data['limit'] ?? 20)->toArray()],
            'show' => $this->show($data['id']),
            'show_run' => ['run' => (app(ShowRun::class)->handle($data['id']) ?? throw new RuntimeException('RUN_NOT_FOUND: No run matches this ID.'))->toArray()],
        };
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function dispatchCreate(array $data): array
    {
        $paths = $data['paths'] ?? [];
        $nickname = $data['nickname'] ?? null;
        $allowTestEdits = (bool) ($data['allow_test_edits'] ?? false);

        return match ($data['operation']) {
            'create' => ['task' => app(CreateTask::class)->handle($data['prompt'], $data['workspace'], $paths, $data['test_path'], nickname: $nickname, allowTestEdits: $allowTestEdits)->toArray()],
            'from_plan' => ['task' => app(CreateTaskFromPlan::class)->handle($data['plan_id'], $data['prompt'], $data['workspace'], $paths, $data['test_path'], nickname: $nickname, allowTestEdits: $allowTestEdits)->toArray()],
            'import_github' => ['task' => app(ImportGitHubIssue::class)->handle($data['issue_url'], $data['workspace'], $paths, $data['test_path'], nickname: $nickname, allowTestEdits: $allowTestEdits)->toArray()],
        };
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function dispatchLifecycle(array $data): array
    {
        return match ($data['operation']) {
            'pr_body' => app(ComposePullRequestBody::class)->handle($data['id'], (bool) ($data['close'] ?? false)),
            'stop' => ['task' => app(StopTask::class)->handle($data['id'])->toArray()],
        };
    }

    /**
     * Refuse an operation that records a human decision. Nothing is read or written; the
     * error names the Artisan command, with the arguments the caller gave, for a person to run.
     *
     * @param  array<string, mixed>  $data
     */
    private function refuseHumanDecision(array $data): never
    {
        $quote = fn (string $value): string => preg_match('~\A[A-Za-z0-9_/.:=@%+,-]+\z~', $value) === 1 ? $value : escapeshellarg($value);
        $task = $quote($data['id']);
        $command = match ($data['operation']) {
            'approve' => 'molly:approve '.$task.' --approve',
            'lock_test' => 'molly:lock-test '.$task.' --approve'
                .implode('', array_map(fn (string $path): string => ' --file='.$quote($path), $data['paths'] ?? []))
                .(isset($data['reason']) ? ' --reason='.$quote($data['reason']) : ''),
            'pr_opened' => 'molly:pr-opened '.$task.' --url='.(isset($data['url']) ? $quote($data['url']) : 'URL').' --approve',
            'merged' => 'molly:merged '.$task.' --sha='.(isset($data['sha']) ? $quote($data['sha']) : 'SHA').' --approve',
            'comment' => 'molly:comment '.$task.' --approve'.(($data['close'] ?? false) ? ' --close' : ''),
            'handoff' => 'molly:handoff '.$task
                .' --from='.($data['from_workspace_id'] ?? 'UUID').' --to='.($data['to_workspace_id'] ?? 'UUID')
                .(isset($data['next_action']) ? ' --action='.$quote($data['next_action']) : '')
                .(isset($data['context']) ? ' --context='.$quote($data['context']) : '')
                .' --approve',
        };

        throw new RuntimeException('HUMAN_APPROVAL_REQUIRED: '.self::HUMAN_DECISIONS[$data['operation']]
            .' molly_task changed nothing. Ask a person to run: php artisan '.$command);
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function dispatchLinks(array $data): array
    {
        return match ($data['operation']) {
            'name' => ['task' => app(NameTask::class)->handle($data['id'], $data['nickname'])->toArray()],
            'link_thread' => ['association' => app(LinkTaskThread::class)->handle($data['id'], $data['thread'])],
        };
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function dispatchAdvice(array $data): array
    {
        return ['advice' => app(RecommendTaskNextStep::class)->handle($data['id'])];
    }

    /** @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function dispatchQueue(array $data): array
    {
        $target = ExecutionTargetRequest::orb($data['orb'] ?? null, $data['orb_runtime'] ?? null, $data['orb_model'] ?? null);
        $result = ['task' => app(QueueTask::class)->handle($data['id'], $data['operation'] === 'retry', $target)->toArray(), 'queued' => true];
        $orb = $target === null ? null : app(LocalOrbProvider::class)->placementOf($result['task']['id']);

        return [...$result, 'orb' => $orb === null ? null : ['id' => $orb->id, 'name' => $orb->name, 'runtime' => $orb->runtime, 'model' => $orb->model, 'queue' => $orb->queue()]];
    }

    /** @return array{task: array<string, mixed>, display_status: string, linked_pr: array{url: string, number: int|null, merge_sha: string|null}|null, issue_url: string|null} */
    private function show(string $id): array
    {
        $task = app(ShowTask::class)->handle($id)
            ?? throw new RuntimeException('TASK_NOT_FOUND: No task matches this ID.');
        $inspection = app(InspectTask::class)->handle($task);

        return [
            'task' => $task->toArray(),
            'display_status' => $inspection['display_status'],
            'linked_pr' => $inspection['linked_pr'],
            'issue_url' => $inspection['issue_url'],
        ];
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->enum(self::OPERATIONS)->description('advice checks limits and may use optional default-off Jev classification through Laravel AI when enabled; it is advisory and state-preserving and does not promise a provider request. It saves advice but does not start or retry work. approve, lock_test, pr_opened, merged, comment, and handoff are human decisions: molly_task refuses them with HUMAN_APPROVAL_REQUIRED, changes nothing, and returns the command a person runs.')->required(),
            'id' => $schema->string()->description('Task nickname or UUID, or a run UUID for show_run.'),
            'nickname' => $schema->string()->description('Optional nickname when saving a task; required for name. Unique, 1 to 64 letters, digits, or hyphens, starting with a letter.'),
            'thread' => $schema->string()->description('Amp thread ID beginning with T-, required for link_thread. Records a user association without starting work.'),
            'plan_id' => $schema->string()->description('Completed or explicitly skipped plan ID, required for from_plan.'),
            'prompt' => $schema->string()->description('Task description; required for create/from_plan. Maximum 8192 bytes, or 1000 for from_plan.'),
            'workspace' => $schema->string()->description('Existing local workspace directory; required when saving a task.'),
            'paths' => $schema->array()->items($schema->string())->description('Relative implementation files Molly may change. The required Pest test is protected unless allow_test_edits is true. For lock_test, the files become --file options in the command a person runs.'),
            'test_path' => $schema->string()->description('Required PHP test file under tests/. It is read-only unless allow_test_edits is true.'),
            'allow_test_edits' => $schema->boolean()->description('Explicit weaker trust model that lets the same writer change the required Pest test. Default false.'),
            'issue_url' => $schema->string()->description('HTTPS github.com issue URL; required for import_github.'),
            'reason' => $schema->string()->description('Why the Pest digest should be locked. For lock_test it becomes --reason in the command a person runs. Maximum 512 bytes.'),
            'close' => $schema->boolean()->description('For pr_body, include closing language only after required checks pass. For comment it becomes --close in the command a person runs. Molly still does not merge.'),
            'url' => $schema->string()->description('HTTPS github.com pull request URL. For pr_opened it becomes --url in the command a person runs; molly_task records nothing.'),
            'sha' => $schema->string()->description('40-character merge commit SHA. For merged it becomes --sha in the command a person runs; molly_task records nothing.'),
            'from_workspace_id' => $schema->string()->description('Sender Bloom workspace UUID. For handoff it becomes --from in the command a person runs.'),
            'to_workspace_id' => $schema->string()->description('Recipient Bloom workspace UUID. For handoff it becomes --to in the command a person runs.'),
            'next_action' => $schema->string()->description('Requested next action. For handoff it becomes --action in the command a person runs. Cannot be merge or open_pull_request.'),
            'context' => $schema->string()->description('Bounded context for the recipient agent. For handoff it becomes --context in the command a person runs. Maximum 8192 bytes.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Task list limit; defaults to 20.'),
            'orb' => $schema->string()->description('For start or retry, the name or ID of a registered Orb to queue the task on. The Orb must be idle and healthy, and the task must live in a linked Git worktree of the Orb\'s repository under its approved worktree root.'),
            'orb_runtime' => $schema->string()->enum(['ollama', 'amp'])->description('For start or retry without orb, queue the task on the first idle, healthy Orb by name with this runtime.'),
            'orb_model' => $schema->string()->description('For start or retry without orb, queue the task on the first idle, healthy Orb by name with this model, such as gpt-oss:20b.'),
        ];
    }
}
