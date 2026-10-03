<?php

namespace Sifrious\Molly\Acceptance;

final readonly class VerificationPlan
{
    /** @param  list<VerificationCheckDefinition>  $checks */
    public function __construct(
        public VerificationMode $mode,
        public array $checks,
        public ?string $baselineRunId = null,
    ) {}

    public static function full(M04CheckCatalog $catalog, VerificationMode $mode): self
    {
        return new self($mode, $catalog->checks());
    }

    /**
     * @param  list<VerificationCheckDefinition>  $definitions
     * @param  list<array<string, mixed>>  $previousChecks
     */
    public static function retry(array $definitions, array $previousChecks): self
    {
        $blocked = [];
        foreach ($previousChecks as $check) {
            if (($check['outcome'] ?? null) === CheckOutcome::BlockedVerifierPermission->value && is_string($check['check_id'] ?? null)) {
                $blocked[$check['check_id']] = true;
            }
        }

        return new self(
            VerificationMode::RetryNativeUi,
            array_values(array_filter(
                $definitions,
                fn (VerificationCheckDefinition $check): bool => isset($blocked[$check->id]),
            )),
        );
    }
}
