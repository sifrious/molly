<?php

namespace Sifrious\Molly\Verification;

class CompletionGate
{
    /**
     * @param  array<string, array{state: VerificationState, policy: VerifierPolicy, failure_action?: FailureAction}>  $checks
     * @return array{completed: bool, blockers: list<string>, outcomes: array<string, array{state: string, policy: string}>}
     */
    public function evaluate(array $checks): array
    {
        $blockers = [];
        $outcomes = [];

        foreach ($checks as $name => $check) {
            $state = $check['state'];
            $policy = $check['policy'];
            $outcomes[$name] = ['state' => $state->value, 'policy' => $policy->value];

            if ($policy === VerifierPolicy::Required && $state !== VerificationState::Pass) {
                $blockers[] = $name;
            }
        }

        return [
            'completed' => $blockers === [],
            'blockers' => $blockers,
            'outcomes' => $outcomes,
        ];
    }
}
