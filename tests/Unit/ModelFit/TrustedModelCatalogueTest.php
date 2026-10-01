<?php

use Illuminate\Support\Facades\File;
use Sifrious\Molly\ModelFit\AdmissionPolicy;
use Sifrious\Molly\ModelFit\TrustedModelCatalogue;

/*
 * The approved catalogue ships in resources/models. These tests load it exactly as the fit
 * decision does, and load changed copies from a temporary directory to prove each refusal.
 */

/** @return array<string, mixed> */
function bundledCatalogueDocument(): array
{
    return json_decode(File::get(TrustedModelCatalogue::BUNDLED), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Write a catalogue document and a checksum that matches it, unless $checksum is given.
 *
 * @param  array<string, mixed>  $document
 */
function writeCatalogue(array $document, ?string $checksum = null): string
{
    $directory = sys_get_temp_dir().'/molly-catalogue-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory);
    $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    File::put($directory.'/catalogue.v1.json', $json);
    File::put($directory.'/catalogue.v1.sha256', ($checksum ?? hash('sha256', $json))."\n");

    return $directory.'/catalogue.v1.json';
}

function loadCatalogue(string $path): TrustedModelCatalogue
{
    return TrustedModelCatalogue::fromVerifiedFiles($path, TrustedModelCatalogue::checksumPath($path), new AdmissionPolicy);
}

/**
 * Check a JSON value against the parts of JSON Schema the catalogue schema uses. Returns
 * the paths that do not conform.
 *
 * @param  array<string, mixed>  $schema
 * @param  array<string, mixed>  $root
 * @return list<string>
 */
function schemaViolations(mixed $value, array $schema, array $root, string $path = '$'): array
{
    if (isset($schema['$ref'])) {
        $schema = data_get($root, str_replace('/', '.', substr($schema['$ref'], 2)));
    }
    $errors = [];
    if (isset($schema['oneOf'])) {
        $matches = array_filter($schema['oneOf'], fn (array $option): bool => schemaViolations($value, $option, $root, $path) === []);

        return count($matches) === 1 ? [] : ["{$path}: matches ".count($matches).' oneOf options'];
    }
    if (array_key_exists('const', $schema) && $value !== $schema['const']) {
        $errors[] = "{$path}: is not ".json_encode($schema['const']);
    }
    if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
        $errors[] = "{$path}: is not one of ".json_encode($schema['enum']);
    }
    $type = $schema['type'] ?? null;
    $typeOk = match ($type) {
        'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
        'array' => is_array($value) && array_is_list($value),
        'string' => is_string($value),
        'integer' => is_int($value),
        'boolean' => is_bool($value),
        default => true,
    };
    if (! $typeOk) {
        return ["{$path}: is not {$type}"];
    }
    if (is_string($value) && isset($schema['pattern']) && preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/', $value) !== 1) {
        $errors[] = "{$path}: does not match {$schema['pattern']}";
    }
    if (is_string($value) && isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
        $errors[] = "{$path}: is shorter than {$schema['minLength']}";
    }
    if (is_int($value) && isset($schema['minimum']) && $value < $schema['minimum']) {
        $errors[] = "{$path}: is below {$schema['minimum']}";
    }
    if ($type === 'object') {
        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $value)) {
                $errors[] = "{$path}: is missing {$key}";
            }
        }
        if (isset($schema['minProperties']) && count($value) < $schema['minProperties']) {
            $errors[] = "{$path}: has fewer than {$schema['minProperties']} properties";
        }
        foreach ($value as $key => $item) {
            if (isset($schema['properties'][$key])) {
                $errors = [...$errors, ...schemaViolations($item, $schema['properties'][$key], $root, "{$path}.{$key}")];
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $errors[] = "{$path}: has unexpected property {$key}";
            }
        }
    }
    if ($type === 'array') {
        if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
            $errors[] = "{$path}: has fewer than {$schema['minItems']} items";
        }
        foreach ($value as $index => $item) {
            $errors = [...$errors, ...schemaViolations($item, $schema['items'] ?? [], $root, "{$path}[{$index}]")];
        }
    }

    return $errors;
}

it('loads the bundled catalogue with two approved Ollama artifacts and a pinned runtime', function () {
    $catalogue = TrustedModelCatalogue::bundled();

    expect($catalogue->catalogueVersion)->toBe('1.0.0')
        ->and($catalogue->policyVersion)->toBe(AdmissionPolicy::VERSION)
        ->and($catalogue->catalogueDigest)->toBe('sha256:'.trim(File::get(TrustedModelCatalogue::checksumPath(TrustedModelCatalogue::BUNDLED))))
        ->and(array_map(fn (array $entry): string => $entry['artifact']['name'], $catalogue->approvedEntries()))->toBe(['gpt-oss:20b', 'gpt-oss:120b-code'])
        ->and($catalogue->runtimes())->toHaveCount(1)
        ->and($catalogue->runtimes()[0])->toMatchArray(['runtime' => 'ollama', 'version' => '0.34.4', 'minimum_os_version' => '14.0', 'platforms' => ['macos-apple-silicon']]);
});

it('records version, origin, licence, and digest for every entry', function () {
    foreach (TrustedModelCatalogue::bundled()->entries() as $entry) {
        expect($entry['identity']['model_version'])->toBeString()->not->toBeEmpty()
            ->and($entry['developer']['origin'])->toBeIn(['us-developed', 'non-us-developed', 'unknown'])
            ->and($entry['license']['spdx_id'])->toBeString()->not->toBeEmpty()
            ->and($entry['artifact']['digest'])->toMatch('/^[a-f0-9]{64}$/')
            ->and($entry['artifact']['size_bytes'])->toBeInt()->toBeGreaterThan(0);
    }
});

it('pins the approved artifacts to the digests recorded on the acceptance Mac', function () {
    $catalogue = TrustedModelCatalogue::bundled();

    expect($catalogue->approvedEntry('gpt-oss:20b')['artifact'])->toMatchArray(['digest' => GPT_OSS_20B_DIGEST, 'size_bytes' => 13793441244, 'install' => ['method' => 'pull']])
        ->and($catalogue->approvedEntry('gpt-oss:120b-code')['artifact'])->toMatchArray([
            'digest' => GPT_OSS_120B_CODE_DIGEST,
            'size_bytes' => 65369818623,
            'install' => ['method' => 'derive', 'from' => ['name' => 'gpt-oss:120b', 'digest' => GPT_OSS_120B_DIGEST, 'size_bytes' => 65369818941], 'parameters' => ['num_ctx' => 65536, 'temperature' => 1]],
        ]);
});

it('keeps the runtime pin separate from the model entries', function () {
    $document = bundledCatalogueDocument();

    expect(array_keys($document))->toBe(['catalogue_version', 'policy_version', 'reviewed_at', 'review_by', 'runtimes', 'entries'])
        ->and($document['runtimes'][0])->not->toHaveKey('artifact')
        ->and(array_column(array_merge(...array_column($document['entries'], 'runtime_compatibility')), 'version_constraint'))->each->toBe($document['runtimes'][0]['version']);
});

it('conforms to the bundled catalogue schema', function () {
    $schema = json_decode(File::get(dirname(TrustedModelCatalogue::BUNDLED).'/catalogue.schema.v1.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(schemaViolations(bundledCatalogueDocument(), $schema, $schema))->toBe([])
        ->and(schemaViolations(['entries' => []] + bundledCatalogueDocument(), $schema, $schema))->toBe([])
        ->and(schemaViolations(array_diff_key(bundledCatalogueDocument(), ['runtimes' => true]), $schema, $schema))->toBe(['$: is missing runtimes']);
});

it('rejects qwen2.5-coder:7b under the origin policy', function () {
    $catalogue = TrustedModelCatalogue::bundled();

    expect($catalogue->decisionFor('qwen2.5-coder:7b')->approved)->toBeFalse()
        ->and($catalogue->decisionFor('qwen2.5-coder:7b')->rejectionReasons)->toBe(['origin_not_us_developed', 'developer_organization_not_us'])
        ->and($catalogue->approvedEntry('qwen2.5-coder:7b'))->toBeNull()
        ->and($catalogue->decisionFor('gpt-oss:120b')->rejectionReasons)->toBe(['model_not_catalogued']);
});

it('never approves an entry whose origin is unknown', function () {
    $document = bundledCatalogueDocument();
    $entry = $document['entries'][0];
    $entry['identity']['model_id'] = 'mystery:7b';
    $entry['artifact']['name'] = 'mystery:7b';
    $entry['developer']['origin'] = 'unknown';
    $entry['developer']['organization_country'] = 'US';

    $decision = (new AdmissionPolicy)->evaluate($entry);
    $entry['admission'] = ['decision' => 'rejected', 'rejection_reasons' => $decision->rejectionReasons];
    $document['entries'][] = $entry;
    $catalogue = loadCatalogue(writeCatalogue($document));

    expect($decision->rejectionReasons)->toBe(['origin_not_us_developed'])
        ->and($catalogue->approvedEntry('mystery:7b'))->toBeNull()
        ->and(array_column(array_column($catalogue->approvedEntries(), 'artifact'), 'name'))->not->toContain('mystery:7b');
});

it('names each missing piece of evidence as a rejection reason', function () {
    $entry = bundledCatalogueDocument()['entries'][0];
    unset($entry['artifact']['weights'], $entry['license']['source']);
    $entry['artifact']['source'] = 'http://registry.ollama.ai/v2/library/gpt-oss/manifests/20b';
    $entry['artifact']['install'] = ['method' => 'derive', 'from' => ['name' => 'gpt-oss:120b']];

    expect((new AdmissionPolicy)->evaluate($entry)->rejectionReasons)->toBe([
        'artifact_source_not_trusted_https',
        'artifact_weights_unknown',
        'artifact_install_invalid',
        'invalid_evidence_uri:license.source',
    ]);
});

it('admits only the exact approved name and manifest digest', function () {
    $catalogue = TrustedModelCatalogue::bundled();

    expect($catalogue->admitArtifact('gpt-oss:20b', GPT_OSS_20B_DIGEST)->approved)->toBeTrue()
        ->and($catalogue->admitArtifact('gpt-oss:20b', 'sha256:'.GPT_OSS_20B_DIGEST)->approved)->toBeTrue()
        ->and($catalogue->admitArtifact('gpt-oss:20b', str_repeat('0', 64))->rejectionReasons)->toBe(['artifact_source_or_digest_not_approved'])
        ->and($catalogue->admitArtifact('gpt-oss:120b', GPT_OSS_120B_DIGEST)->rejectionReasons)->toBe(['artifact_source_or_digest_not_approved']);
});

it('pins provenance to the catalogue, the policy, and the artifact', function () {
    $provenance = TrustedModelCatalogue::bundled()->provenanceFor('gpt-oss:120b-code');

    expect($provenance)->toMatchArray([
        'catalogue_version' => '1.0.0',
        'policy_version' => '1.0.0',
        'model_version' => '2025-08-05',
        'upstream_revision' => 'sha256:'.GPT_OSS_120B_DIGEST,
        'artifact_source' => 'https://registry.ollama.ai/v2/library/gpt-oss/manifests/120b',
        'artifact_digest' => 'sha256:'.GPT_OSS_120B_CODE_DIGEST,
    ])->and($provenance['catalogue_digest'])->toStartWith('sha256:')
        ->and(TrustedModelCatalogue::bundled()->provenanceFor('qwen2.5-coder:7b'))->toBeNull();
});

it('fails integrity verification when the catalogue changes without its checksum', function () {
    $path = writeCatalogue(['catalogue_version' => 'tampered'] + bundledCatalogueDocument(), str_repeat('0', 64));

    expect(fn () => loadCatalogue($path))->toThrow(RuntimeException::class, 'CATALOGUE_INTEGRITY_FAILED: The model catalogue failed integrity verification.');
});

it('refuses a catalogue written for another admission policy', function () {
    $path = writeCatalogue(['policy_version' => '0.9.0'] + bundledCatalogueDocument());

    expect(fn () => loadCatalogue($path))->toThrow(RuntimeException::class, 'CATALOGUE_POLICY_STALE: The model catalogue was written for admission policy "0.9.0", and Molly applies policy 1.0.0.');
});

it('refuses a declared approval that the policy does not reach', function () {
    $document = bundledCatalogueDocument();
    $document['entries'][2]['admission'] = ['decision' => 'approved', 'rejection_reasons' => []];

    expect(fn () => loadCatalogue(writeCatalogue($document)))->toThrow(RuntimeException::class, 'CATALOGUE_INVALID: The declared admission decision for qwen2.5-coder:7b does not match policy.');
});

it('refuses an approved entry whose runtime is not pinned', function () {
    $document = bundledCatalogueDocument();
    $document['runtimes'][0]['version'] = '0.35.0';

    expect(fn () => loadCatalogue(writeCatalogue($document)))->toThrow(RuntimeException::class, 'CATALOGUE_INVALID: gpt-oss:20b names runtime ollama 0.34.4, which the catalogue does not pin.');
});

it('is stale only after its review date', function () {
    $catalogue = TrustedModelCatalogue::bundled();

    expect($catalogue->reviewBy)->toBe('2027-03-28')
        ->and($catalogue->staleOn('2027-03-28'))->toBeFalse()
        ->and($catalogue->staleOn('2027-03-29T00:00:00Z'))->toBeTrue()
        ->and($catalogue->staleOn('2026-09-28'))->toBeFalse();
});
