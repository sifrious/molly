<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\InspectRun;
use Sifrious\Molly\Actions\InspectTaskChain;
use Sifrious\Molly\Actions\ListConversations;
use Sifrious\Molly\Actions\ShowConversation;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyInspectCommand extends Command
{
    protected $signature = 'molly:inspect
        {target? : task:<ref>, run:<id>, conversation:<id>, or bare task ref}
        {--task= : Task name or ID}
        {--run= : Run ID}
        {--conversation= : Conversation ID}
        {--conversations : List conversations}
        {--ensure-conversations : Persist conversations from runs while inspecting}
        {--json : Print JSON only}';

    protected $description = 'Inspect Molly tasks, runs, and conversations with bidirectional links';

    public function handle(
        InspectTaskChain $tasks,
        InspectRun $runs,
        ListConversations $listConversations,
        ShowConversation $showConversation,
    ): int {
        try {
            $ensure = (bool) $this->option('ensure-conversations');
            $payload = null;

            if ($this->option('conversations')) {
                $payload = ['status' => 'ok', ...$listConversations->handle($this->option('task') ?: null)];
            } elseif (is_string($this->option('conversation')) && $this->option('conversation') !== '') {
                $payload = ['status' => 'ok', 'conversation' => $showConversation->handle((string) $this->option('conversation'))];
            } elseif (is_string($this->option('run')) && $this->option('run') !== '') {
                $payload = ['status' => 'ok', 'inspection' => $runs->handle((string) $this->option('run'), $ensure)];
            } elseif (is_string($this->option('task')) && $this->option('task') !== '') {
                $payload = ['status' => 'ok', 'inspection' => $tasks->handle((string) $this->option('task'), $ensure)];
            } else {
                $target = trim((string) ($this->argument('target') ?? ''));
                if ($target === '') {
                    throw new \InvalidArgumentException('Pass a task ref, --task, --run, --conversation, or --conversations.');
                }
                if (str_starts_with($target, 'run:')) {
                    $payload = ['status' => 'ok', 'inspection' => $runs->handle(substr($target, 4), $ensure)];
                } elseif (str_starts_with($target, 'conversation:')) {
                    $payload = ['status' => 'ok', 'conversation' => $showConversation->handle(substr($target, 13))];
                } elseif (str_starts_with($target, 'task:')) {
                    $payload = ['status' => 'ok', 'inspection' => $tasks->handle(substr($target, 5), $ensure)];
                } else {
                    $payload = ['status' => 'ok', 'inspection' => $tasks->handle($target, $ensure)];
                }
            }

            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
