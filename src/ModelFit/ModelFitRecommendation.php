<?php

namespace Sifrious\Molly\ModelFit;

/**
 * One approved model assessed against one machine. `fit` is the memory tier the model
 * reached, recommended_fit or minimum_fit, even when `status` is already_installed.
 * `loaded` says Ollama holds this exact artifact, matched by name and digest.
 * `measured` and `required` hold the numbers the reason codes compare.
 */
final readonly class ModelFitRecommendation
{
    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $constraints
     * @param  array<string, mixed>  $measured
     * @param  array<string, mixed>  $required
     */
    public function __construct(
        public ModelFitStatus $status,
        public ?string $modelId,
        public ?string $runtime,
        public array $reasons,
        public array $constraints = [],
        public ?ModelFitStatus $fit = null,
        public array $measured = [],
        public array $required = [],
        public bool $installed = false,
        public bool $verified = false,
        public int $downloadBytes = 0,
        public bool $loaded = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'model' => $this->modelId,
            'runtime' => $this->runtime,
            'status' => $this->status->value,
            'fit' => $this->fit?->value,
            'reasons' => $this->reasons,
            'constraints' => $this->constraints,
            'installed' => $this->installed,
            'verified' => $this->verified,
            'loaded' => $this->loaded,
            'download_bytes' => $this->downloadBytes,
            'measured' => $this->measured,
            'required' => $this->required,
        ];
    }
}
