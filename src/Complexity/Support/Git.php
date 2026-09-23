<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Every git invocation in the package goes through here: array argv only
 * (no shell. Windows-safe, Process::fake()-testable), executed at the
 * configured root.
 */
final class Git
{
    private ?GitStatus $status = null;

    private ?string $root = null;

    private bool $shallow = false;

    public function __construct(private readonly CleverConfig $config) {}

    /**
     * Cheap ordered checks, memoized per configured root: binary present,
     * inside a work tree, has at least one commit. Also records whether the
     * clone is shallow. A root change re-runs the checks.
     */
    public function preflight(): GitStatus
    {
        $root = $this->config->root();

        if ($this->status !== null && $this->root === $root) {
            return $this->status;
        }

        $this->root = $root;
        $this->shallow = false;

        try {
            if (! $this->run(['git', '--version'])->successful()) {
                return $this->status = GitStatus::NotFound;
            }

            if (! $this->run(['git', 'rev-parse', '--is-inside-work-tree'])->successful()) {
                return $this->status = GitStatus::NotARepo;
            }

            if (! $this->run(['git', 'rev-parse', 'HEAD'])->successful()) {
                return $this->status = GitStatus::NoCommits;
            }

            $shallow = $this->run(['git', 'rev-parse', '--is-shallow-repository']);

            $this->shallow = $shallow->successful() && trim($shallow->output()) === 'true';
        } catch (Throwable) {
            return $this->status = GitStatus::NotFound;
        }

        return $this->status = GitStatus::Ok;
    }

    public function isShallow(): bool
    {
        $this->preflight();

        return $this->shallow;
    }

    /**
     * Run `git log --no-merges` with the given extra arguments and return
     * its raw output, or null on failure. core.quotepath=off keeps
     * non-ASCII paths unescaped in --name-only output. the one intentional
     * flag the hand-verify one-liners do not pass.
     *
     * @param  list<string>  $extraArgs
     */
    public function log(array $extraArgs): ?string
    {
        try {
            $result = $this->run(['git', '-c', 'core.quotepath=off', 'log', '--no-merges', ...$extraArgs]);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() ? $result->output() : null;
    }

    /**
     * Branch, short sha, and dirty state for the report envelope.
     */
    public function context(): GitContext
    {
        if ($this->preflight() !== GitStatus::Ok) {
            return GitContext::unavailable();
        }

        try {
            $branch = $this->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
            $sha = $this->run(['git', 'rev-parse', '--short', 'HEAD']);
            $status = $this->run(['git', 'status', '--porcelain']);
        } catch (Throwable) {
            return GitContext::unavailable();
        }

        if (! $branch->successful() || ! $sha->successful()) {
            return GitContext::unavailable();
        }

        return new GitContext(
            available: true,
            branch: trim($branch->output()),
            sha: trim($sha->output()),
            dirty: $status->successful() ? trim($status->output()) !== '' : null,
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): ProcessResult
    {
        return Process::path($this->config->root())
            ->timeout(120)
            ->env(['GIT_OPTIONAL_LOCKS' => '0'])
            ->run($command);
    }
}
