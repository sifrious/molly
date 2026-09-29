<?php

namespace Sifrious\Molly\Contracts;

use InvalidArgumentException;

/**
 * Where a task should run. A local request names nothing. An Orb request names a registered
 * Orb by name or ID in target_id, or leaves target_id empty and requires a runtime, a model,
 * or both, so Molly chooses an idle Orb that has them.
 */
final readonly class ExecutionTargetRequest
{
    public const RUNTIMES = ['ollama', 'amp'];

    public function __construct(
        public ExecutionTargetKind $kind,
        public ?string $targetId = null,
        public ?string $reason = null,
        public ?string $runtime = null,
        public ?string $model = null,
    ) {
        if ($this->kind === ExecutionTargetKind::Orb && ($this->targetId === null || $this->targetId === '') && $this->runtime === null && $this->model === null) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: An Orb execution target needs a target_id. Name an Orb, or require a runtime or model.');
        }
        if ($this->kind === ExecutionTargetKind::Local && $this->targetId !== null) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: A local execution target cannot name a remote target_id.');
        }
        if ($this->kind === ExecutionTargetKind::Local && ($this->runtime !== null || $this->model !== null)) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: A local execution target cannot require an Orb runtime or model.');
        }
        if ($this->runtime !== null && ! in_array($this->runtime, self::RUNTIMES, true)) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: execution_target.runtime must be ollama or amp.');
        }
        if ($this->model !== null && trim($this->model) === '') {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: execution_target.model cannot be empty.');
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $kind = ExecutionTargetKind::tryFrom(JsonDocument::string($data, 'kind'));
        if ($kind === null) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: execution_target.kind must be local or orb.');
        }

        return new self(
            $kind,
            JsonDocument::optionalString($data, 'target_id'),
            JsonDocument::optionalString($data, 'reason'),
            JsonDocument::optionalString($data, 'runtime'),
            JsonDocument::optionalString($data, 'model'),
        );
    }

    public static function local(?string $reason = null): self
    {
        return new self(ExecutionTargetKind::Local, null, $reason ?? 'Local execution is the default.');
    }

    /**
     * The Orb request that command options or tool arguments describe, or null when they name
     * no Orb and require no runtime or model, which means local execution.
     */
    public static function orb(?string $targetId, ?string $runtime = null, ?string $model = null, ?string $reason = null): ?self
    {
        $blank = fn (?string $value): ?string => $value === null || trim($value) === '' ? null : trim($value);
        [$targetId, $runtime, $model] = [$blank($targetId), $blank($runtime), $blank($model)];
        if ($targetId === null && $runtime === null && $model === null) {
            return null;
        }

        return new self(ExecutionTargetKind::Orb, $targetId, $reason, $runtime === null ? null : strtolower($runtime), $model);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'target_id' => $this->targetId,
            'reason' => $this->reason,
            ...($this->runtime === null ? [] : ['runtime' => $this->runtime]),
            ...($this->model === null ? [] : ['model' => $this->model]),
        ];
    }
}
