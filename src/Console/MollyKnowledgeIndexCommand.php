<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sifrious\Molly\Actions\IndexLaravelKnowledge;
use Sifrious\Molly\Actions\IndexNativePhpKnowledge;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

final class MollyKnowledgeIndexCommand extends Command
{
    protected $signature = 'molly:knowledge:index {namespace=laravel : Knowledge namespace} {--laravel-version= : Installed Laravel major version} {--json : Print JSON only}';

    protected $description = 'Build the local Laravel or NativePHP knowledge graph';

    public function handle(IndexLaravelKnowledge $laravel, IndexNativePhpKnowledge $nativephp): int
    {
        try {
            $namespace = (string) $this->argument('namespace');
            $result = match ($namespace) {
                'laravel' => $laravel->handle($this->option('laravel-version')),
                'nativephp' => $this->indexNativephp($nativephp),
                default => throw new RuntimeException('KNOWLEDGE_NAMESPACE_INVALID: Choose laravel or nativephp.'),
            };
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } elseif ($namespace === 'nativephp') {
                note('NativePHP Desktop v2 and Mobile v4 knowledge indexed. Tracks stay separate. Installed NativePHP packages are not required or claimed.');
                table(['Sources', 'Nodes', 'Edges'], [[$result['sources'], $result['nodes'], $result['edges']]]);
                note('Database: '.$result['database']);
            } else {
                note('Laravel '.$result['version'].' queue, routing, testing, validation, container, eloquent, and events knowledge indexed.');
                table(['Sources', 'Nodes', 'Edges'], [[$result['sources'], $result['nodes'], $result['edges']]]);
                note('Database: '.$result['database']);
            }

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

    /** @return array<string, mixed> */
    private function indexNativephp(IndexNativePhpKnowledge $nativephp): array
    {
        if (filled($this->option('laravel-version'))) {
            throw new RuntimeException('KNOWLEDGE_VERSION_INVALID: NativePHP indexing does not use --laravel-version.');
        }

        return $nativephp->handle();
    }
}
