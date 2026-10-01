<?php

namespace Sifrious\Molly\Seams;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

final class ValidateSchema
{
    public function handle(mixed $input, array $schema, string $file): array
    {
        $this->localReferences($schema, $file);
        if (($schema['$schema'] ?? null) !== 'https://json-schema.org/draft/2020-12/schema') {
            throw new SeamError('INSTRUCTION_SCHEMA_UNSUPPORTED', 'Use the supported JSON Schema draft.', $file, '2020-12', $schema['$schema'] ?? null);
        }
        try {
            $validator = new Validator;
            $result = $validator->validate(json_decode(json_encode($this->objects($input, $schema), JSON_THROW_ON_ERROR)), json_decode(json_encode($schema, JSON_THROW_ON_ERROR)));
        } catch (\Throwable $exception) {
            throw new SeamError('INSTRUCTION_PACK_INVALID', 'The input schema cannot be evaluated: '.$exception->getMessage(), $file);
        }

        return $result->isValid() ? [] : (new ErrorFormatter)->format($result->error());
    }

    private function localReferences(array $schema, string $file): void
    {
        foreach ($schema as $key => $value) {
            if (in_array($key, ['$ref', '$dynamicRef', '$recursiveRef'], true)
                && (! is_string($value) || ! str_starts_with($value, '#'))) {
                throw new SeamError('INSTRUCTION_PACK_INVALID', 'Schema references must stay inside the same file.', $file);
            }
            if (is_array($value)) {
                $this->localReferences($value, $file);
            }
        }
    }

    private function objects(mixed $input, array $schema): mixed
    {
        if (! is_array($input)) {
            return $input;
        }
        if (($schema['type'] ?? null) === 'object' && ($input === [] || ! array_is_list($input))) {
            $result = [];
            foreach ($input as $key => $value) {
                $result[$key] = $this->objects($value, $schema['properties'][$key] ?? []);
            }

            return (object) $result;
        }
        if (($schema['type'] ?? null) === 'array') {
            return array_map(fn (mixed $value): mixed => $this->objects($value, $schema['items'] ?? []), $input);
        }

        return $input;
    }
}
