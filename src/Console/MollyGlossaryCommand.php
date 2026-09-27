<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Sifrious\Molly\Journal\JournalRenderer;
use Sifrious\Molly\Workspace;
use Throwable;

use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

class MollyGlossaryCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:glossary
        {path? : Workspace path, the same as --workspace}
        {--workspace= : Workspace whose .molly/GLOSSARY.md the terms belong to (defaults to the application)}
        {--json : Print JSON only}';

    protected $description = 'List the Molly terms written to .molly/GLOSSARY.md, with their source';

    public function handle(JournalRenderer $renderer): int
    {
        try {
            $root = (new Workspace((string) ($this->option('workspace') ?: $this->argument('path') ?: base_path())))->path;
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['status' => 'error', 'error' => $exception->getMessage(), 'terms' => []]);
        }

        $path = $root.'/.molly/GLOSSARY.md';
        $terms = array_map(fn (array $entry): array => [
            'id' => Str::slug($entry['term']),
            'term' => $entry['term'],
            'definition' => $entry['definition'],
            'origin' => 'molly',
            'provenance' => ['source' => 'src/Journal/JournalRenderer.php', 'method' => 'JournalRenderer::glossaryTerms'],
            'links' => [
                ['kind' => 'file', 'ref' => $path],
                ['kind' => 'source', 'ref' => 'src/Journal/JournalRenderer.php'],
            ],
        ], $renderer->glossaryTerms());

        if ($this->option('json')) {
            $this->line(json_encode(['status' => 'ok', 'workspace' => $root, 'path' => $path, 'exported' => is_file($path), 'terms' => $terms], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        table(['Term', 'Definition'], array_map(fn (array $term): array => [$term['term'], $term['definition']], $terms));
        note(is_file($path) ? 'Exported to '.$path.'.' : 'Not exported yet. Run php artisan molly:journal TASK --project to write '.$path.'.');

        return self::SUCCESS;
    }
}
