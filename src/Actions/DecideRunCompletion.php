<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Verification\CompletionGate;
use Sifrious\Molly\Verification\VerificationState;
use Sifrious\Molly\Verification\VerifierPolicy;

class DecideRunCompletion
{
    public function __construct(
        private CompletionGate $completion,
        private ReviewChanges $review,
    ) {}

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, string>  $after
     * @return array{completed: bool, outcomes: array<string, array{state: string, policy: string}>, blockers: list<string>}
     */
    public function handle(array $report, array $after): array
    {
        $checks = [
            'pest' => [
                'state' => VerificationState::fromObserved($report['verification']['status'] ?? null),
                'policy' => $this->policy('pest'),
            ],
            'tarpit' => [
                'state' => $this->review->passed($report['review'] ?? [], $after)
                    ? VerificationState::Pass
                    : VerificationState::Fail,
                'policy' => $this->policy('tarpit'),
            ],
        ];

        if (($report['mode'] ?? null) === 'parallel') {
            $checks['parallel_join'] = [
                'state' => $this->branchesPassed($report['branches'] ?? [])
                    ? VerificationState::Pass
                    : VerificationState::Fail,
                'policy' => $this->policy('parallel_join'),
            ];
        }

        return $this->completion->evaluate($checks);
    }

    /** @param list<array<string, mixed>> $branches */
    private function branchesPassed(array $branches): bool
    {
        $kinds = array_column($branches, 'kind');
        sort($kinds);

        if (count($branches) !== 2 || $kinds !== ['review', 'verification']) {
            return false;
        }

        foreach ($branches as $branch) {
            if (($branch['status'] ?? null) !== 'passed' || empty($branch['result_ref']) || empty($branch['finished_at'])) {
                return false;
            }
        }

        return true;
    }

    private function policy(string $name): VerifierPolicy
    {
        $policy = VerifierPolicy::tryFrom((string) config('molly.verification.'.$name, 'required'));

        if ($policy === null) {
            throw new RuntimeException('VERIFICATION_POLICY_INVALID: Choose required or advisory for '.$name.'.');
        }

        return $policy;
    }
}
