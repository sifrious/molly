<?php

namespace Sifrious\Molly\Execution;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Illuminate\Support\Str;
use RuntimeException;
use Sifrious\Molly\Agents\LocalOllama;
use Sifrious\Molly\Contracts\ExecutionTargetKind;
use Sifrious\Molly\Contracts\ExecutionTargetRequest;
use Sifrious\Molly\Contracts\ExecutionTargetSnapshot;
use Sifrious\Molly\Models\Orb;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace\GitBinary;
use Sifrious\Molly\Workspace\ObserveCheckout;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Orbs that run on this machine. Each Orb is a row in molly_orbs with a UUID identity, one
 * runtime and model, one repository it may change, and one approved directory that holds the
 * Git worktrees its tasks run in. A task runs on an Orb only in its own linked worktree of that
 * repository under that directory, one task per Orb at a time.
 *
 * Hosted Orbs are not shipped. SelectExecutionTarget is the seam a hosted provider would join.
 */
final class LocalOrbProvider
{
    public const PROVIDER = 'local';

    public function __construct(private SandboxCapability $sandbox, private ObserveCheckout $observe) {}

    public function register(string $name, string $runtime, ?string $model, string $repository, string $worktreeRoot): Orb
    {
        $name = strtolower(trim($name));
        if (preg_match('/\A[a-z][a-z0-9-]{0,63}\z/', $name) !== 1 || Str::isUuid($name)) {
            throw new RuntimeException('ORB_NAME_INVALID: Use 1 to 64 lowercase letters, numbers, or hyphens, starting with a letter.');
        }
        if (Orb::where('name', $name)->exists()) {
            throw new RuntimeException('ORB_NAME_TAKEN: Another Orb, active or revoked, already uses the name '.$name.'. Choose another name.');
        }
        $runtime = strtolower(trim($runtime));
        if (! in_array($runtime, ExecutionTargetRequest::RUNTIMES, true)) {
            throw new RuntimeException('ORB_RUNTIME_INVALID: Use --runtime=ollama or --runtime=amp.');
        }
        $model = $model === null || trim($model) === '' ? null : trim($model);
        if ($runtime === 'ollama' && ($model === null || str_contains($model, 'cloud'))) {
            throw new RuntimeException('ORB_MODEL_INVALID: An Ollama Orb needs a local model name from ollama list, such as gpt-oss:20b.');
        }
        if ($runtime === 'amp' && $model !== null) {
            throw new RuntimeException('ORB_MODEL_INVALID: An Amp Orb takes no model. Amp chooses the model.');
        }

        [$repositoryPath, $gitDir] = $this->repository($repository);
        $root = $this->approvedRoot($worktreeRoot, $gitDir);
        $remote = $this->observe->handle($repositoryPath)['remote_identity'];

        try {
            $orb = Orb::create([
                'name' => $name,
                'provider' => self::PROVIDER,
                'device' => gethostname() ?: 'unknown',
                'runtime' => $runtime,
                'model' => $model,
                'repository_path' => $repositoryPath,
                'repository_git_dir' => $gitDir,
                'repository_remote_identity' => $remote,
                'worktree_root' => $root,
                'health' => 'unknown',
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new RuntimeException('ORB_NAME_TAKEN: Another Orb, active or revoked, already uses the name '.$name.'. Choose another name.', 0, $exception);
        }

        return $this->check($orb);
    }

    public function find(string $reference): Orb
    {
        return Orb::findByReference($reference)
            ?? throw new RuntimeException('ORB_NOT_FOUND: No registered Orb has the name or ID '.$reference.'. List Orbs with php artisan molly:orbs.');
    }

    /** @return Collection<int, Orb> every Orb, oldest first, rechecking the health of each active Orb when $check is true */
    public function all(bool $check = false): Collection
    {
        $orbs = Orb::query()->orderBy('created_at')->orderBy('name')->get();

        return $check ? $orbs->map(fn (Orb $orb): Orb => $orb->revoked_at === null ? $this->check($orb) : $orb) : $orbs;
    }

    /**
     * Ask the Orb's runtime whether it can answer, record the result as the Orb's health, and
     * record the time as its heartbeat. An Ollama Orb is healthy when Ollama answers on its
     * loopback URL and lists the model. An Amp Orb is healthy when amp usage succeeds.
     */
    public function check(Orb $orb): Orb
    {
        [$health, $reason, $identity] = $orb->runtime === 'amp' ? $this->checkAmp() : $this->checkOllama((string) $orb->model);
        $orb->forceFill([
            'health' => $health,
            'health_reason' => $reason,
            'runtime_identity' => $identity,
            'health_checked_at' => now(),
            'heartbeat_at' => now(),
        ])->save();

        return $orb;
    }

    /** Record that the Orb is alive, from a run checkpoint. */
    public function heartbeat(string $orbId): void
    {
        Orb::whereKey($orbId)->update(['heartbeat_at' => now()]);
    }

    /**
     * Revoke an Orb. It keeps its identity and history and takes no new work. A task queued on
     * it stays pending and loses its place, so it can be queued on another Orb. A running task
     * is asked to stop at its next step, as molly:stop does.
     *
     * @return array{orb: Orb, released_task: ?Task, stopping_task: ?Task, already_revoked: bool}
     */
    public function revoke(string $reference, ?string $reason = null): array
    {
        $orb = $this->find($reference);
        if ($orb->revoked_at !== null) {
            return ['orb' => $orb, 'released_task' => null, 'stopping_task' => null, 'already_revoked' => true];
        }
        $reason = $reason === null || trim($reason) === '' ? null : mb_strcut(trim($reason), 0, 512, 'UTF-8');

        [$released, $stopping] = DB::transaction(function () use ($orb, $reason): array {
            Orb::whereKey($orb->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
            $task = $orb->activeTask();
            if ($task?->status === 'pending') {
                Orb::whereKey($orb->id)->where('current_task_id', $task->id)->update(['current_task_id' => null, 'reserved_at' => null, 'reserved_prompt_sha256' => null]);

                return [$task, null];
            }
            if ($task?->status === 'running') {
                Task::whereKey($task->id)->where('status', 'running')->whereNull('stop_requested_at')->update(['stop_requested_at' => now()]);

                return [null, $task];
            }

            return [null, null];
        });

        return ['orb' => $orb->refresh(), 'released_task' => $released, 'stopping_task' => $stopping, 'already_revoked' => false];
    }

    /**
     * Choose an Orb for the task and reserve it. A request that names an Orb gets that Orb or
     * a coded refusal. A request that requires a runtime or model gets the first Orb, by name
     * and then ID, that has them and passes every check, so the same records give the same
     * choice. Each check runs again before the task launches.
     */
    public function place(ExecutionTargetRequest $request, Task $task): ExecutionTargetSnapshot
    {
        if ($request->kind !== ExecutionTargetKind::Orb) {
            throw new RuntimeException('ORB_REQUEST_INVALID: Only an Orb request can be placed on an Orb.');
        }
        if ($request->targetId !== null && $request->targetId !== '') {
            $orb = $this->find($request->targetId);
            $this->reserve($orb, $task, $request);

            return $this->snapshot($orb, $request->reason ?? 'The request named Orb '.$orb->name.'.');
        }

        $refusals = [];
        $candidates = Orb::query()->whereNull('revoked_at')
            ->when($request->runtime !== null, fn ($query) => $query->where('runtime', $request->runtime))
            ->when($request->model !== null, fn ($query) => $query->where('model', $request->model))
            ->orderBy('name')->orderBy('id')->get();
        foreach ($candidates as $orb) {
            try {
                $this->reserve($orb, $task, $request);

                return $this->snapshot($orb, $request->reason ?? 'Orb '.$orb->name.' is the first idle Orb by name that runs '.$this->describe($request).'.');
            } catch (RuntimeException $exception) {
                $refusals[] = $orb->name.': '.$exception->getMessage();
            }
        }

        throw new RuntimeException('ORB_UNAVAILABLE: No registered Orb that runs '.$this->describe($request).' can take task '.$task->reference().'.'
            .($refusals === [] ? ' Register one with php artisan molly:orb-register.' : ' '.implode(' ', $refusals)));
    }

    /**
     * Take the Orb for the task inside the claim transaction. The update succeeds only while
     * the Orb is not revoked and is free, already held by this task, or held by a task that has
     * completed or no longer exists, so two claims can never both hold one Orb. It also refuses
     * a claim while another task is running in the same worktree.
     */
    public function occupy(string $orbId, Task $task): void
    {
        if (! $this->takeReservation($orbId, $task)) {
            $orb = Orb::find($orbId);
            if ($orb === null) {
                throw new RuntimeException('ORB_NOT_FOUND: Orb '.$orbId.' is no longer registered.');
            }
            if ($orb->revoked_at !== null) {
                throw $this->revoked($orb);
            }
            throw $this->busy($orb);
        }
        $other = Task::where('workspace', $task->workspace)->whereKeyNot($task->id)->where('status', 'running')->first();
        if ($other !== null) {
            throw $this->occupied($other, (string) $task->workspace, 'running');
        }
    }

    /**
     * Refuse an Orb start whose task workspace no longer resolves to the canonical path saved
     * with the task, before Molly takes the task lock, which is its first write in the worktree.
     */
    public function assertCanonicalWorkspace(Task $task): void
    {
        $saved = (string) $task->workspace;
        clearstatcache();
        $real = realpath($saved);
        if ($real !== $saved) {
            throw new RuntimeException('ORB_WORKTREE_INVALID: The worktree '.$saved.' for task '.$task->reference().($real === false ? ' no longer exists.' : ' now resolves to '.$real.'. Molly runs an Orb task only in the canonical path saved with the task.'));
        }
    }

    /**
     * Check the placement again right before the task launches: the Orb is not revoked and is
     * still reserved for this task, the prompt is the one it was placed with, and the worktree
     * resolves to the same canonical path under the approved root.
     *
     * @param  array<string, mixed>  $evidence  The placement evidence from evidence().
     */
    public function revalidate(Task $task, array $evidence): string
    {
        $orb = Orb::find($evidence['target_id'] ?? '');
        if ($orb === null) {
            throw new RuntimeException('ORB_NOT_FOUND: The Orb this task was placed on is no longer registered.');
        }
        if ($orb->revoked_at !== null) {
            throw $this->revoked($orb);
        }
        if ($orb->current_task_id !== $task->id) {
            throw $this->busy($orb);
        }
        $task = $task->fresh() ?? throw new RuntimeException('TASK_NOT_FOUND: Molly could not find that task.');
        if (! hash_equals((string) $orb->reserved_prompt_sha256, hash('sha256', (string) $task->prompt))) {
            throw new RuntimeException('ORB_PROMPT_CHANGED: The prompt of task '.$task->reference().' changed after it was placed on Orb '.$orb->name.'. Molly launches only the prompt it placed. Save a new task instead.');
        }
        $worktree = $this->worktree($orb, $task);
        if ($worktree !== ($evidence['worktree'] ?? null)) {
            throw new RuntimeException('ORB_WORKTREE_INVALID: The worktree for task '.$task->reference().' changed from '.($evidence['worktree'] ?? 'none').' to '.$worktree.' after placement.');
        }

        return $worktree;
    }

    public function snapshot(Orb $orb, string $reason): ExecutionTargetSnapshot
    {
        $capabilities = ['git_worktree', 'runtime_'.$orb->runtime, 'workspace_mapping'];
        if ($this->sandbox->available()) {
            $capabilities[] = 'sandbox_profile';
        }

        return new ExecutionTargetSnapshot(
            ExecutionTargetKind::Orb,
            $orb->id,
            $capabilities,
            $this->sandbox->available(),
            'local_orb',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $reason,
        );
    }

    /**
     * What the run report, receipts, and journal record about the Orb an attempt runs on: the
     * target snapshot, the Orb's identity, runtime, model, health, and observed runtime version,
     * the repository it may change, the canonical worktree, and the placed prompt's digest.
     *
     * @return array<string, mixed>
     */
    public function evidence(ExecutionTargetSnapshot $snapshot, Task $task): array
    {
        $orb = Orb::findOrFail($snapshot->targetId);

        return [
            ...$snapshot->toArray(),
            'orb' => [
                'id' => $orb->id,
                'name' => $orb->name,
                'provider' => $orb->provider,
                'device' => $orb->device,
                'runtime' => $orb->runtime,
                'model' => $orb->model,
                'health' => $orb->health,
                'health_checked_at' => $orb->health_checked_at?->toIso8601String(),
                'runtime_identity' => $orb->runtime_identity,
            ],
            'repository' => [
                'path' => $orb->repository_path,
                'git_common_dir' => $orb->repository_git_dir,
                'remote_identity' => $orb->repository_remote_identity,
            ],
            'worktree_root' => $orb->worktree_root,
            'worktree' => $this->worktree($orb, $task),
            'prompt_sha256' => $orb->reserved_prompt_sha256,
            'placed_at' => $orb->reserved_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> how molly:orbs, molly:orb-register, and molly:orb-revoke report an Orb */
    public function describeOrb(Orb $orb): array
    {
        $task = $orb->activeTask();

        return [
            'id' => $orb->id,
            'name' => $orb->name,
            'provider' => $orb->provider,
            'device' => $orb->device,
            'availability' => $orb->availability(),
            'health' => $orb->health,
            'health_reason' => $orb->health_reason,
            'health_checked_at' => $orb->health_checked_at?->toIso8601String(),
            'heartbeat_at' => $orb->heartbeat_at?->toIso8601String(),
            'runtime' => $orb->runtime,
            'model' => $orb->model,
            'capabilities' => $this->snapshot($orb, 'Listed.')->capabilities,
            'runtime_identity' => $orb->runtime_identity,
            'repository' => [
                'path' => $orb->repository_path,
                'git_common_dir' => $orb->repository_git_dir,
                'remote_identity' => $orb->repository_remote_identity,
            ],
            'worktree_root' => $orb->worktree_root,
            'queue' => $orb->queue(),
            'current_task' => $task === null ? null : [
                'id' => $task->id,
                'reference' => $task->reference(),
                'status' => $task->status,
                'workspace' => $task->workspace,
                'reserved_at' => $orb->reserved_at?->toIso8601String(),
            ],
            'revoked_at' => $orb->revoked_at?->toIso8601String(),
            'revoked_reason' => $orb->revoked_reason,
            'registered_at' => $orb->created_at?->toIso8601String(),
        ];
    }

    /** The Orb that holds a reservation for the task, if any. */
    public function placementOf(string $taskId): ?Orb
    {
        return Orb::where('current_task_id', $taskId)->first();
    }

    /**
     * Clear the task's reservation on the given Orbs unless the task is running, which means
     * another process owns its attempt and frees the Orb when that attempt ends.
     *
     * @param  list<string>  $orbIds
     */
    public function releaseUnlessRunning(string $taskId, array $orbIds): void
    {
        if ($orbIds !== [] && Task::whereKey($taskId)->value('status') !== 'running') {
            Orb::whereIn('id', $orbIds)->where('current_task_id', $taskId)->update(['current_task_id' => null, 'reserved_at' => null, 'reserved_prompt_sha256' => null]);
        }
    }

    private function reserve(Orb $orb, Task $task, ExecutionTargetRequest $request): void
    {
        if ($orb->revoked_at !== null) {
            throw $this->revoked($orb);
        }
        $holder = $orb->activeTask();
        if ($holder !== null && $holder->id !== $task->id) {
            throw $this->busy($orb, $holder);
        }
        if (($request->runtime !== null && $request->runtime !== $orb->runtime) || ($request->model !== null && $request->model !== $orb->model)) {
            throw new RuntimeException('ORB_CAPABILITY_MISMATCH: Orb '.$orb->name.' runs '.$orb->runtime.($orb->model === null ? '' : ' '.$orb->model).', not '.$this->describe($request).'.');
        }
        $worktree = $this->worktree($orb, $task);
        $elsewhere = Orb::where('current_task_id', $task->id)->whereKeyNot($orb->id)->first();
        if ($elsewhere !== null) {
            throw new RuntimeException('ORB_TASK_PLACED: Task '.$task->reference().' is already placed on Orb '.$elsewhere->name.'. Let that attempt run, or run it on that Orb with --orb='.$elsewhere->name.'.');
        }
        $running = Task::where('workspace', $worktree)->whereKeyNot($task->id)->where('status', 'running')->first();
        if ($running !== null) {
            throw $this->occupied($running, $worktree, 'running');
        }
        $queued = Task::where('workspace', $worktree)->whereKeyNot($task->id)->whereIn('status', Orb::HOLDING_STATUSES)
            ->whereIn('id', Orb::query()->select('current_task_id')->whereNotNull('current_task_id'))->first();
        if ($queued !== null) {
            throw $this->occupied($queued, $worktree, 'placed on an Orb');
        }
        $this->check($orb);
        if ($orb->health !== 'healthy') {
            throw new RuntimeException('ORB_UNHEALTHY: Orb '.$orb->name.' failed its health check. '.$orb->health_reason);
        }
        if (! $this->takeReservation($orb->id, $task)) {
            throw $this->busy($orb->refresh());
        }
        $orb->refresh();
    }

    /**
     * Reserve the Orb for the task in one conditional update: the Orb is free, or its task has
     * completed or no longer exists. Keeps the prompt digest of this task's existing reservation.
     */
    private function takeReservation(string $orbId, Task $task): bool
    {
        $free = Orb::whereKey($orbId)->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('current_task_id')
            ->orWhereNotIn('current_task_id', Task::query()->select('id')->whereIn('status', Orb::HOLDING_STATUSES)));
        if ($free->update(['current_task_id' => $task->id, 'reserved_at' => now(), 'reserved_prompt_sha256' => hash('sha256', (string) $task->prompt)]) === 1) {
            return true;
        }

        return Orb::whereKey($orbId)->whereNull('revoked_at')->where('current_task_id', $task->id)->exists();
    }

    /**
     * The task's worktree after checking, without trusting any saved path: the Orb's approved
     * root still resolves to its saved path; the worktree still resolves to exactly the
     * canonical path saved with the task, so no part of it is a symbolic link; it sits under
     * that root; and Git reports it as a linked worktree of the repository the Orb may change.
     */
    private function worktree(Orb $orb, Task $task): string
    {
        clearstatcache();
        $root = realpath($orb->worktree_root);
        if ($root === false || $root !== $orb->worktree_root) {
            throw new RuntimeException('ORB_WORKTREE_ROOT_INVALID: The approved worktree root '.$orb->worktree_root.' for Orb '.$orb->name.' is missing or now resolves to '.($root ?: 'nothing').'. Register a new Orb for the current directory.');
        }
        $saved = (string) $task->workspace;
        $real = realpath($saved);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('ORB_WORKTREE_INVALID: The worktree '.$saved.' for task '.$task->reference().' no longer exists.');
        }
        if ($real !== $saved) {
            throw new RuntimeException('ORB_WORKTREE_INVALID: The worktree '.$saved.' for task '.$task->reference().' now resolves to '.$real.'. Molly runs an Orb task only in the canonical path saved with the task.');
        }
        if (! str_starts_with($real, $root.'/')) {
            throw new RuntimeException('ORB_WORKTREE_OUTSIDE_ROOT: Task '.$task->reference().' runs in '.$real.', which is not under '.$root.', the worktree root approved for Orb '.$orb->name.'. Create the task in a worktree under that root.');
        }
        $common = $this->gitPath($real, '--git-common-dir');
        if ($common === null || $common !== $orb->repository_git_dir) {
            throw new RuntimeException('ORB_REPOSITORY_NOT_GRANTED: '.$real.' is not a worktree of '.$orb->repository_path.', the repository Orb '.$orb->name.' may change.');
        }
        if ($this->gitPath($real, '--git-dir') === $common) {
            throw new RuntimeException('ORB_WORKTREE_NOT_LINKED: '.$real.' is the main checkout of '.$orb->repository_path.', not a linked worktree. Give the task its own worktree with git worktree add.');
        }

        return $real;
    }

    /** @return array{0: string, 1: string} the canonical repository path and its canonical Git common directory */
    private function repository(string $path): array
    {
        $real = realpath($path);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('ORB_REPOSITORY_INVALID: '.$path.' is not an existing directory. Pass --repository with the checkout the Orb may change.');
        }
        GitBinary::require();
        $common = $this->gitPath($real, '--git-common-dir');
        if ($common === null) {
            throw new RuntimeException('ORB_REPOSITORY_INVALID: '.$real.' is not in a Git repository. An Orb runs tasks only in Git worktrees.');
        }

        return [$real, $common];
    }

    private function approvedRoot(string $path, string $gitDir): string
    {
        $real = realpath($path);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('ORB_WORKTREE_ROOT_INVALID: '.$path.' is not an existing directory. Create it, then register the Orb again.');
        }
        if ($real === '/' || str_starts_with($real.'/', $gitDir.'/')) {
            throw new RuntimeException('ORB_WORKTREE_ROOT_INVALID: '.$real.' cannot hold task worktrees. Choose a directory outside the repository\'s .git directory, such as ../orbs.');
        }
        if (! is_writable($real)) {
            throw new RuntimeException('ORB_WORKTREE_ROOT_INVALID: '.$real.' is not writable by this user.');
        }

        return $real;
    }

    /** The canonical absolute path Git reports for --git-dir or --git-common-dir, or null outside a repository. */
    private function gitPath(string $directory, string $flag): ?string
    {
        $process = new Process(['git', '-C', $directory, 'rev-parse', '--path-format=absolute', $flag]);
        $process->setTimeout(15);
        $process->run();
        $path = trim($process->getOutput());
        if (! $process->isSuccessful() || $path === '') {
            return null;
        }

        return realpath($path) ?: null;
    }

    /** @return array{0: string, 1: ?string, 2: ?array<string, mixed>} */
    private function checkOllama(string $model): array
    {
        try {
            LocalOllama::validate($model);
        } catch (Throwable $exception) {
            return ['unhealthy', $exception->getMessage(), null];
        }
        $url = rtrim((string) config('ai.providers.ollama.url'), '/');
        try {
            $tags = Http::acceptJson()->connectTimeout(2)->timeout(5)->withoutRedirecting()->get($url.'/api/tags');
        } catch (Throwable) {
            return ['unhealthy', 'ORB_RUNTIME_UNREACHABLE: Molly could not connect to Ollama at '.$url.'. Start Ollama with ollama serve.', null];
        }
        $models = $tags->json('models');
        if (! $tags->successful() || ! is_array($models)) {
            return ['unhealthy', 'ORB_RUNTIME_UNREACHABLE: Ollama at '.$url.' answered HTTP '.$tags->status().' without a model list.', null];
        }
        $entry = collect($models)->first(fn (mixed $entry): bool => is_array($entry)
            && (in_array($entry['name'] ?? null, [$model, $model.':latest'], true) || in_array($entry['model'] ?? null, [$model, $model.':latest'], true)));
        if ($entry === null) {
            return ['unhealthy', 'ORB_MODEL_MISSING: Ollama at '.$url.' has no model named '.$model.'. Run ollama pull '.$model.'.', null];
        }
        try {
            $version = Http::acceptJson()->connectTimeout(2)->timeout(5)->withoutRedirecting()->get($url.'/api/version')->json('version');
        } catch (Throwable) {
            $version = null;
        }

        return ['healthy', null, [
            'runtime' => 'ollama',
            'url' => $url,
            'version' => is_string($version) && $version !== '' ? $version : 'unavailable',
            'model' => $model,
            'digest' => is_string($entry['digest'] ?? null) ? $entry['digest'] : 'unavailable',
        ]];
    }

    /** @return array{0: string, 1: ?string, 2: ?array<string, mixed>} */
    private function checkAmp(): array
    {
        try {
            $result = ProcessFacade::timeout(15)->run(['amp', 'usage']);
            $healthy = $result->successful();
        } catch (Throwable) {
            $healthy = false;
        }

        return $healthy
            ? ['healthy', null, ['runtime' => 'amp']]
            : ['unhealthy', 'ORB_RUNTIME_UNAVAILABLE: The Amp CLI is missing or not signed in. Install it and run amp login.', null];
    }

    private function describe(ExecutionTargetRequest $request): string
    {
        return trim(($request->runtime ?? 'any runtime').($request->model === null ? '' : ' '.$request->model));
    }

    private function revoked(Orb $orb): RuntimeException
    {
        return new RuntimeException('ORB_REVOKED: Orb '.$orb->name.' was revoked at '.$orb->revoked_at?->toIso8601String().' and takes no work.');
    }

    private function busy(Orb $orb, ?Task $holder = null): RuntimeException
    {
        $holder ??= $orb->activeTask();
        $state = match (true) {
            $holder === null => 'reserved for another task',
            $holder->status === 'running' => 'working on task '.$holder->reference(),
            default => 'reserved for task '.$holder->reference().', which is queued on it',
        };

        return new RuntimeException('ORB_BUSY: Orb '.$orb->name.' is '.$state.'. Each Orb takes one task at a time. Wait for that task to finish, or choose another Orb.');
    }

    private function occupied(Task $other, string $worktree, string $state): RuntimeException
    {
        return new RuntimeException('ORB_WORKTREE_OCCUPIED: Task '.$other->reference().' is '.$state.' in '.$worktree.'. No two active runs write to the same worktree.');
    }
}
