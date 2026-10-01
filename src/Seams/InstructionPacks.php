<?php

namespace Sifrious\Molly\Seams;

use Composer\Semver\Semver;
use Illuminate\Support\Facades\Validator;
use Sifrious\Molly\Knowledge\ComposerLock;

final class InstructionPacks
{
    public const FILES = ['manifest.json', 'instructions.md', 'input.schema.json', 'plan.json', 'examples/good.pest.stub', 'examples/bad.pest.stub', 'templates/feature.pest.stub'];

    public function __construct(private PackFiles $files, private ValidateSchema $schemas, private ComposerLock $lock) {}

    public function bundledPath(): string
    {
        return dirname(__DIR__, 2).'/resources/seams/packs';
    }

    public function catalogue(): array
    {
        return $this->files->json($this->files->read(dirname(__DIR__, 2), 'resources/seams/catalogue.json'), 'catalogue.json');
    }

    public function list(string $workspace): array
    {
        $rows = [];
        $paths = [...(glob($this->bundledPath().'/*/*/manifest.json') ?: []), ...(glob($this->files->path($workspace, config('molly-seams.path')).'/*/*/manifest.json') ?: [])];
        $seen = [];
        foreach ($paths as $path) {
            $id = basename(dirname($path, 2)).'/'.basename(dirname($path));
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            try {
                $pack = $this->inspect($workspace, $id);
                $rows[] = ['id' => $id, 'manifest' => $pack['manifest'], 'source' => $pack['source'], 'digest' => $pack['digest']];
            } catch (SeamError $error) {
                $rows[] = ['id' => $id, 'error' => $error->toArray()];
            }
        }

        return $rows;
    }

    public function inspect(string $workspace, string $id, bool $checkPackage = true): array
    {
        $this->id($id);
        $local = $this->files->path($workspace, config('molly-seams.path').'/'.$id);
        $override = file_exists($local);
        if ($override && ! is_dir($local)) {
            throw new SeamError('INSTRUCTION_OVERRIDE_INCOMPLETE', 'The application pack must be a complete directory.', $local);
        }
        $root = $override ? $local : $this->files->path($this->bundledPath(), $id);
        $pack = $this->load($root, $id, $override ? 'INSTRUCTION_OVERRIDE_INCOMPLETE' : 'INSTRUCTION_PACK_INVALID');
        if ($checkPackage) {
            $this->compatible($workspace, $pack['manifest']);
        }
        $original = $this->publication($root);
        $changes = [];
        foreach ($pack['files'] as $path => $file) {
            $before = $original['files'][$path] ?? null;
            if (! is_string($before) || hash('sha256', $before) !== $file['sha256']) {
                $changes[] = $path;
            }
        }

        return [...$pack, 'source' => $override ? 'application' : 'package', 'source_path' => $root,
            'published_version' => $original['pack_version'] ?? null, 'local_changes' => $changes];
    }

    public function compare(string $workspace, string $id): array
    {
        $this->id($id);
        $root = $this->files->path($workspace, config('molly-seams.path').'/'.$id);
        if (! is_dir($root)) {
            $root = $this->files->path($this->bundledPath(), $id);
        }
        // Comparison must remain available when local edits no longer validate.
        $local = ['source_path' => $root, 'files' => []];
        foreach (self::FILES as $file) {
            $local['files'][$file] = ['contents' => is_file($this->files->path($root, $file)) ? $this->files->read($root, $file) : null];
        }
        try {
            $local['manifest'] = $this->files->json($local['files']['manifest.json']['contents'] ?? '', $root.'/manifest.json');
        } catch (SeamError) {
            $local['manifest'] = [];
        }
        $new = $this->load($this->files->path($this->bundledPath(), $id), $id, 'INSTRUCTION_PACK_INVALID');
        $original = $this->publication($local['source_path']);
        $rows = [];
        foreach (self::FILES as $path) {
            $base = $original['files'][$path] ?? null;
            $ours = $local['files'][$path]['contents'];
            $theirs = $new['files'][$path]['contents'];
            $conflict = $base === null || ($ours !== $base && $theirs !== $base && $ours !== $theirs);
            $rows[$path] = ['original' => $base, 'local' => $ours, 'package' => $theirs,
                'local_changed' => $ours !== $base, 'package_changed' => $theirs !== $base,
                'conflict' => $conflict];
        }
        $conflicts = array_keys(array_filter($rows, fn (array $row): bool => $row['conflict']));

        return ['id' => $id, 'original_version' => $original['pack_version'] ?? null,
            'local_version' => $local['manifest']['pack_version'] ?? null, 'package_version' => $new['manifest']['pack_version'],
            'files' => $rows, 'conflicts' => $conflicts, 'error' => $conflicts === [] ? null : 'INSTRUCTION_UPGRADE_CONFLICT',
            'next_action' => 'Review all three versions, edit the complete application pack, then create a new plan revision. This comparison does not write files.'];
    }

    private function load(string $root, string $id, string $missingCode): array
    {
        if (! is_dir($root)) {
            throw new SeamError($missingCode, 'No complete instruction pack exists for this seam.', $root);
        }
        $contents = [];
        $total = 0;
        foreach (self::FILES as $file) {
            $bytes = $this->files->read($root, $file, $missingCode);
            $total += strlen($bytes);
            if ($total > (int) config('molly-seams.max_pack_bytes', 2097152)) {
                throw new SeamError('INSTRUCTION_PACK_INVALID', 'The pack exceeds the configured byte limit.', $root);
            }
            $contents[$file] = ['path' => $root.'/'.$file, 'contents' => $bytes, 'sha256' => hash('sha256', $bytes)];
        }
        $manifest = $this->files->json($contents['manifest.json']['contents'], $root.'/manifest.json');
        if (($manifest['schema_version'] ?? null) !== 1) {
            throw new SeamError('INSTRUCTION_SCHEMA_UNSUPPORTED', 'Use instruction pack schema version 1.', $root.'/manifest.json', 1, $manifest['schema_version'] ?? null);
        }
        $validation = Validator::make($manifest, [
            'seam_id' => ['required', 'in:'.$id], 'pack_version' => ['required', 'regex:/\A[0-9]+\.[0-9]+\.[0-9]+\z/'],
            'template_version' => ['required', 'string'], 'package' => ['required', 'array:name,versions'],
            'package.name' => ['required', 'string'], 'package.versions' => ['required', 'string'],
            'source_guide_id' => ['required', 'regex:/\AMME-[0-9]+\z/'], 'guide_url' => ['required', 'url:https'],
            'tickets' => ['required', 'array:parent,generator,verification'], 'tickets.*' => ['required', 'regex:/\AMME-[0-9]+\z/'],
            'layer' => ['required', 'string'], 'files' => ['required', 'array'],
            'adapter' => ['required', 'string'], 'capabilities' => ['required', 'array', 'min:1'], 'capabilities.*' => ['required', 'string'],
        ]);
        if ($validation->fails() || $manifest['files'] !== self::FILES) {
            throw new SeamError('INSTRUCTION_PACK_INVALID', 'The manifest must identify the seam and every versioned file.', $root.'/manifest.json', self::FILES, $validation->errors()->toArray());
        }
        $schema = $this->files->json($contents['input.schema.json']['contents'], $root.'/input.schema.json');
        $this->schemas->handle(new \stdClass, $schema, $root.'/input.schema.json');
        $plan = $this->files->json($contents['plan.json']['contents'], $root.'/plan.json');
        $validation = Validator::make($plan, ['schema_version' => ['required', 'in:1'], 'steps' => ['required', 'array', 'min:1'],
            'steps.*' => ['required', 'array:id,handler,arguments,after'], 'steps.*.id' => ['required', 'string', 'distinct', 'regex:/\A[a-z][a-z0-9_-]*\z/'],
            'steps.*.handler' => ['required', 'string'], 'steps.*.arguments' => ['present', 'array'], 'steps.*.after' => ['present', 'array'],
            'steps.*.after.*' => ['required', 'string'],
        ]);
        if ($validation->fails() || array_diff(array_keys($plan), ['schema_version', 'steps']) !== []) {
            throw new SeamError('INSTRUCTION_PACK_INVALID', 'Plan steps select handlers, arguments and earlier prerequisites only.', $root.'/plan.json', null, $validation->errors()->toArray());
        }
        $seen = [];
        foreach ($plan['steps'] as $step) {
            if (array_diff($step['after'], $seen) !== []) {
                throw new SeamError('INSTRUCTION_PACK_INVALID', 'Each prerequisite must name an earlier step. Cycles are not allowed.', $root.'/plan.json', $seen, $step['after']);
            }
            $seen[] = $step['id'];
        }
        $hashes = array_map(fn (array $file): string => $file['sha256'], $contents);

        return ['manifest' => $manifest, 'schema' => $schema, 'plan' => $plan, 'files' => $contents,
            'digest' => hash('sha256', $this->files->canonical($hashes))];
    }

    private function publication(string $root): array
    {
        $path = $this->files->path($root, 'publication.json');
        if (! is_file($path)) {
            return [];
        }

        return $this->files->json($this->files->read($root, 'publication.json'), $path);
    }

    private function compatible(string $workspace, array $manifest): void
    {
        $path = $this->files->path($workspace, 'composer.lock');
        if (! is_file($path)) {
            throw new SeamError('PACKAGE_UNAVAILABLE', 'Commit composer.lock before creating a seam plan.', $path);
        }
        $packages = $this->lock->read($workspace)['packages'];
        $package = $manifest['package'];
        $version = $packages[$package['name']] ?? null;
        if ($version === null) {
            throw new SeamError('PACKAGE_UNAVAILABLE', 'The seam package is not recorded in composer.lock.', $path, $package['name'], null);
        }
        try {
            $compatible = Semver::satisfies($version, $package['versions']);
        } catch (\Throwable) {
            throw new SeamError('INSTRUCTION_PACK_INVALID', 'The manifest contains an invalid package version constraint.', 'manifest.json');
        }
        if (! $compatible) {
            throw new SeamError('VERSION_UNSUPPORTED', 'The locked package version is outside the pack support range.', $path, $package['versions'], $version);
        }
    }

    private function id(string $id): void
    {
        if (! preg_match('~\A[a-z0-9]+(?:-[a-z0-9]+)*/[a-z0-9]+(?:-[a-z0-9]+)*\z~D', $id)) {
            throw new SeamError('INPUT_INVALID', 'Use a package/seam identifier from the seam list.', $id);
        }
    }
}
