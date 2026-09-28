<?php

namespace Sifrious\Molly\Redaction;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A JSON array column that is redacted when it is saved and again when it is read,
 * so reports stored before redaction existed are also redacted on the way out.
 * Pass `settings` to also replace every value under a secret-named key.
 */
final class RedactedJson implements CastsAttributes
{
    public function __construct(private string $mode = 'value') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $this->redact($decoded, $attributes) : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($this->redact($value, $attributes), JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $attributes */
    private function redact(mixed $value, array $attributes): mixed
    {
        $workspace = is_string($attributes['workspace'] ?? null) ? $attributes['workspace'] : null;
        $redactor = app(SecretRedactor::class);

        return $this->mode === 'settings' ? $redactor->settings($value, $workspace) : $redactor->value($value, $workspace);
    }
}
