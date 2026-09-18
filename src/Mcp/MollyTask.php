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
use Sifrious\Molly\Actions\ApproveTask;
use Sifrious\Molly\Actions\ComposePullRequestBody;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\CreateTaskFromPlan;
use Sifrious\Molly\Actions\HandOffTask;
use Sifrious\Molly\Actions\ImportGitHubIssue;
use Sifrious\Molly\Actions\LinkTaskThread;
use Sifrious\Molly\Actions\ListTasks;
use Sifrious\Molly\Actions\NameTask;
use Sifrious\Molly\Actions\PublishGitHubIssueStatus;
use Sifrious\Molly\Actions\QueueTask;
use Sifrious\Molly\Actions\RecommendTaskNextStep;
use Sifrious\Molly\Actions\ShowRun;
use Sifrious\Molly\Actions\ShowTask;
use Sifrious\Molly\Actions\StopTask;

#[Name('molly_task')]
#[Description('Save bounded tasks, read evidence, name tasks, record an Amp thread link, import a GitHub issue, request stop, or queue start/retry. create/from_plan/import_github require workspace and test_path; paths lists optional additional files. Task operations accept nicknames or UUIDs. Creating or linking a task does not execute code. start/retry require a background queue and worker; queued=true means requested, not running or passed. import_github reads GitHub using the host gh login. comment and approve require approve=true and never start an agent. pr_body and handoff print evidence and envelopes; they do not open pull requests or create worktrees.')]
#[IsDestructive]
class MollyTask extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'operation' => ['required', 'in:list,show,create,from_plan,start,retry,stop,show_run,import_github,comment,approve,pr_body,handoff,name,link_thread,advice'],
            'id' => ['required_if:operation,show,start,retry,stop,show_run,comment,approve,pr_body,handoff,name,link_thread,advice', 'string', 'max:100'],
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
            'approve' => ['required_if:operation,comment,approve', 'boolean'],
            'close' => ['sometimes', 'boolean'],
            'from_workspace_id' => ['required_if:operation,handoff', 'uuid'],
            'to_workspace_id' => ['required_if:operation,handoff', 'uuid'],
            'next_action' => ['sometimes', 'string', 'max:64'],
            'context' => ['sometimes', 'string', 'max:8192'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        try {
            $result = match ($data['operation']) {
                'list' => ['tasks' => app(ListTasks::class)->handle($data['limit'] ?? 20)->toArray()],
                'show' => ['task' => (app(ShowTask::class)->handle($data['id']) ?? throw new RuntimeException('TASK_NOT_FOUND: No task matches this ID.'))->toArray()],
                'show_run' => ['run' => (app(ShowRun::class)->handle($data['id']) ?? throw new RuntimeException('RUN_NOT_FOUND: No run matches this ID.'))->toArray()],
                'create' => ['task' => app(CreateTask::class)->handle($data['prompt'], $data['workspace'], $data['paths'] ?? [], $data['test_path'], nickname: $data['nickname'] ?? null, allowTestEdits: (bool) ($data['allow_test_edits'] ?? false))->toArray()],
                'from_plan' => ['task' => app(CreateTaskFromPlan::class)->handle($data['plan_id'], $data['prompt'], $data['workspace'], $data['paths'] ?? [], $data['test_path'], nickname: $data['nickname'] ?? null, allowTestEdits: (bool) ($data['allow_test_edits'] ?? false))->toArray()],
                'import_github' => ['task' => app(ImportGitHubIssue::class)->handle($data['issue_url'], $data['workspace'], $data['paths'] ?? [], $data['test_path'], nickname: $data['nickname'] ?? null, allowTestEdits: (bool) ($data['allow_test_edits'] ?? false))->toArray()],
                'comment' => app(PublishGitHubIssueStatus::class)->handle($data['id'], (bool) ($data['approve'] ?? false), (bool) ($data['close'] ?? false)),
                'approve' => app(ApproveTask::class)->handle($data['id'], (bool) ($data['approve'] ?? false)),
                'pr_body' => app(ComposePullRequestBody::class)->handle($data['id'], (bool) ($data['close'] ?? false)),
                'handoff' => ['handoff' => app(HandOffTask::class)->handle($data['id'], $data['from_workspace_id'], $data['to_workspace_id'], $data['next_action'] ?? 'implement', $data['context'] ?? 'Implement the locked acceptance test without changing protected files.')->toArray()],
                'name' => ['task' => app(NameTask::class)->handle($data['id'], $data['nickname'])->toArray()],
                'link_thread' => ['association' => app(LinkTaskThread::class)->handle($data['id'], $data['thread'])],
                'advice' => ['advice' => app(RecommendTaskNextStep::class)->handle($data['id'])],
                'start', 'retry' => ['task' => app(QueueTask::class)->handle($data['id'], $data['operation'] === 'retry')->toArray(), 'queued' => true],
                'stop' => ['task' => app(StopTask::class)->handle($data['id'])->toArray()],
            };

            return Response::structured($result);
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->enum(['list', 'show', 'create', 'from_plan', 'start', 'retry', 'stop', 'show_run', 'import_github', 'comment', 'approve', 'pr_body', 'handoff', 'name', 'link_thread', 'advice'])->description('advice checks limits and may request optional TypeSafe evaluation. It saves advice but does not start or retry work. comment and approve require approve=true and never start an agent.')->required(),
            'id' => $schema->string()->description('Task nickname or UUID, or a run UUID for show_run.'),
            'nickname' => $schema->string()->description('Optional nickname when saving a task; required for name. Unique, 1 to 64 letters, digits, or hyphens, starting with a letter.'),
            'thread' => $schema->string()->description('Amp thread ID beginning with T-, required for link_thread. Records a user association without starting work.'),
            'plan_id' => $schema->string()->description('Completed or explicitly skipped plan ID, required for from_plan.'),
            'prompt' => $schema->string()->description('Task description; required for create/from_plan. Maximum 8192 bytes, or 1000 for from_plan.'),
            'workspace' => $schema->string()->description('Existing local workspace directory; required when saving a task.'),
            'paths' => $schema->array()->items($schema->string())->description('Relative implementation files Molly may change. The required Pest test is protected unless allow_test_edits is true.'),
            'test_path' => $schema->string()->description('Required PHP test file under tests/. It is read-only unless allow_test_edits is true.'),
            'allow_test_edits' => $schema->boolean()->description('Explicit weaker trust model that lets the same writer change the required Pest test. Default false.'),
            'issue_url' => $schema->string()->description('HTTPS github.com issue URL; required for import_github.'),
            'approve' => $schema->boolean()->description('Required true for comment and approve. Molly never posts or records human approval without this flag.'),
            'close' => $schema->boolean()->description('Include closing language only after required checks pass. Molly still does not merge.'),
            'from_workspace_id' => $schema->string()->description('Sender Bloom workspace UUID; required for handoff.'),
            'to_workspace_id' => $schema->string()->description('Recipient Bloom workspace UUID; required for handoff.'),
            'next_action' => $schema->string()->description('Requested next action for handoff. Cannot be merge or open_pull_request.'),
            'context' => $schema->string()->description('Bounded context for the recipient agent. Maximum 8192 bytes.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Task list limit; defaults to 20.'),
        ];
    }
}
