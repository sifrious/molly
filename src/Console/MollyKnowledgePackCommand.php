<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\CollectRunKnowledge;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

final class MollyKnowledgePackCommand extends Command
{
    protected $signature = 'molly:knowledge:pack {prompt : Task prompt to inspect} {--file=* : Allowed file paths} {--test=tests/Feature/ExampleTest.php : Pest test path} {--json : Print JSON only}';

    protected $description = 'Show JIT Laravel context-pack query inputs, selected items, and selection reasons without running an agent';

    public function handle(CollectRunKnowledge $knowledge): int
    {
        try {
            $files = [];
            foreach ($this->option('file') as $path) {
                if (is_string($path) && $path !== '') {
                    $files[$path] = null;
                }
            }
            $pack = $knowledge->pack(
                (string) $this->argument('prompt'),
                $files,
                (string) $this->option('test'),
            )->toArray();

            if ($this->option('json')) {
                $this->line(json_encode($pack, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            intro('Molly JIT context pack');
            note('status: '.$pack['status']);
            note('version: '.($pack['version'] ?? 'none'));
            note('reason: '.$pack['reason']);
            note('prompt_digest: '.$pack['query']['prompt_digest']);
            note('needles: '.(implode(', ', $pack['query']['needles']) ?: '(none)'));
            note('concepts: '.(implode(', ', $pack['query']['concepts']) ?: '(none)'));
            if ($pack['items'] === []) {
                note('No items selected.');

                return self::SUCCESS;
            }

            table(['Concept', 'Matched', 'Truncated', 'Selection reason'], array_map(fn (array $item): array => [
                $item['concept'],
                $item['matched'] ? 'yes' : 'no',
                $item['truncated'] ? 'yes' : 'no',
                $item['selection_reason'],
            ], $pack['items']));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
