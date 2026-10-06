<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Seams\ConsoleTestAdapter;
use Sifrious\Molly\Seams\HttpTestAdapter;
use Sifrious\Molly\Seams\InstructionPacks;
use Sifrious\Molly\Seams\PackFiles;
use Sifrious\Molly\Seams\SeamError;
use Sifrious\Molly\Seams\ValidateSchema;
use Sifrious\Molly\Workspace;

final class PreviewSeamTests
{
    public function __construct(private InstructionPacks $packs, private ValidateSchema $schemas, private PackFiles $files) {}

    public function handle(string $workspace, string $seam, array $contract): array
    {
        return $this->fromPack($workspace, $this->packs->inspect($workspace, $seam), $contract);
    }

    public function fromPack(string $workspace, array $pack, array $contract): array
    {
        $core = match ($pack['manifest']['adapter']) {
            'http' => 'laravel-framework/controllers/input.schema.json',
            'console' => 'laravel-framework/commands/input.schema.json',
            default => throw new SeamError('HANDLER_UNAVAILABLE', 'No test adapter is registered for this pack.', 'manifest.json'),
        };
        $coreSchema = $this->files->json($this->files->read($this->packs->bundledPath(), $core), $core);
        $required = $this->schemas->handle($contract, $coreSchema, $core);
        if ($required !== []) {
            throw new SeamError('CONTRACT_INCOMPLETE', 'Instruction edits cannot remove the adapter input requirements.', $core, null, $required);
        }
        $errors = $this->schemas->handle($contract, $pack['schema'], 'input.schema.json');
        if ($errors !== []) {
            throw new SeamError('CONTRACT_INCOMPLETE', 'Supply the required state and explicit behavior outcomes.', 'input.schema.json', null, $errors);
        }
        $contract = json_decode($this->files->canonical($contract), true, flags: JSON_THROW_ON_ERROR);
        $files = new Workspace($workspace);
        $files->taskPaths($contract['production_paths'], $contract['test_path']);
        $files->read($contract['production_paths']);
        $target = $files->read([$contract['path']])[$contract['path']];
        if ($target === null && $contract['target_state'] !== 'planned') {
            throw new SeamError('TARGET_UNRESOLVED', 'The named target is missing. Declare a planned target or correct its path.', $contract['path']);
        }
        if (! in_array($contract['path'], $contract['production_paths'], true)) {
            throw new SeamError('INPUT_INVALID', 'The target must be inside the declared production scope.', $contract['path']);
        }
        $control = $contract['negative_control'];
        if (! in_array($control['path'], $contract['production_paths'], true) || $control['find'] === $control['replace']) {
            throw new SeamError('INPUT_INVALID', 'The negative control must change one approved production file.', $control['path']);
        }
        $ids = array_column($contract['cases'], 'id');
        if (count(array_unique($ids)) !== count($ids) || ! in_array($control['case_id'], $ids, true)) {
            throw new SeamError('CONTRACT_INCOMPLETE', 'Cases need unique IDs and the control must name one of them.', 'cases');
        }
        $adapter = match ($pack['manifest']['adapter']) {
            'http' => app(HttpTestAdapter::class),
            'console' => app(ConsoleTestAdapter::class),
            default => throw new SeamError('HANDLER_UNAVAILABLE', 'No test adapter is registered for this pack.', 'manifest.json', null, $pack['manifest']['adapter']),
        };
        $template = $pack['files']['templates/feature.pest.stub']['contents'];
        if (substr_count($template, '{{cases}}') !== 1) {
            throw new SeamError('INSTRUCTION_PACK_INVALID', 'The template must contain exactly one {{cases}} placeholder.', 'templates/feature.pest.stub');
        }
        // Templates may explain the generated cases, but cannot replace assertions or execute setup.
        foreach (token_get_all(str_replace('{{cases}}', '', $template)) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                throw new SeamError('INSTRUCTION_PACK_INVALID', 'Keep PHP setup in registered handlers. Templates contain the PHP opening tag, comments and {{cases}}.', 'templates/feature.pest.stub');
            }
        }
        $render = fn (bool $baseline): string => str_replace('{{cases}}', implode("\n\n", array_map(fn (array $case): string => $adapter->renderCase($case, $baseline), $contract['cases'])), $template);
        $contents = $render(false);
        $before = $files->read([$contract['test_path']])[$contract['test_path']];

        return ['schema_version' => 1, 'seam_id' => $pack['manifest']['seam_id'], 'pack_digest' => $pack['digest'],
            'contract' => $contract, 'contract_digest' => hash('sha256', $this->files->canonical($contract)),
            'path' => $contract['test_path'], 'before_digest' => $before === null ? null : hash('sha256', $before),
            'contents' => $contents, 'sha256' => hash('sha256', $contents), 'baseline_contents' => $render(true),
            'cases' => $ids, 'change' => $before === null ? 'create' : ($before === $contents ? 'unchanged' : 'conflict')];
    }
}
