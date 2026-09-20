<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Verification\CompletionGate;
use Sifrious\Molly\Verification\FailureAction;
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
     * @return array{completed: bool, outcomes: array<string, array{state: string, policy: string, failure_action: string}>, blockers: list<string>}
     */
    public function handle(array $report, array $after): array
    {
        $checks = [
            'pest' => [
                'state' => $this->pestState($report['verification'] ?? []),
                'policy' => $this->policy('pest'),
                'failure_action' => $this->failureAction('pest', FailureAction::Retry),
            ],
            'tarpit' => [
                'state' => $this->review->passed($report['review'] ?? [], $after)
                    ? VerificationState::Pass
                    : VerificationState::Fail,
                'policy' => $this->policy('tarpit'),
                'failure_action' => $this->failureAction('tarpit', FailureAction::Retry),
            ],
        ];

        if (($report['mode'] ?? null) === 'parallel') {
            $checks['parallel_join'] = [
                'state' => $this->branchesPassed($report['branches'] ?? [])
                    ? VerificationState::Pass
                    : VerificationState::Fail,
                'policy' => $this->policy('parallel_join'),
                'failure_action' => $this->failureAction('parallel_join', FailureAction::Retry),
            ];
        }

        $this->includeFalseGreen($checks, $report);

        return $this->decide($checks);
    }

    /**
     * Build verification outcomes when a run stops or fails before the normal completion decision.
     * Verifiers that never produced evidence are recorded as NOT_RUN (never omitted).
     *
     * @param  array<string, mixed>  $report
     * @return array{completed: bool, outcomes: array<string, array{state: string, policy: string, failure_action: string}>, blockers: list<string>}
     */
    public function forTerminated(array $report): array
    {
        $checks = [
            'pest' => [
                'state' => $this->pestTerminatedState($report),
                'policy' => $this->policy('pest'),
                'failure_action' => $this->failureAction('pest', FailureAction::Retry),
            ],
            'tarpit' => [
                'state' => $this->tarpitTerminatedState($report),
                'policy' => $this->policy('tarpit'),
                'failure_action' => $this->failureAction('tarpit', FailureAction::Retry),
            ],
        ];

        if (($report['mode'] ?? null) === 'parallel') {
            $checks['parallel_join'] = [
                'state' => $this->parallelJoinTerminatedState($report),
                'policy' => $this->policy('parallel_join'),
                'failure_action' => $this->failureAction('parallel_join', FailureAction::Retry),
            ];
        }

        $this->includeFalseGreen($checks, $report);

        return $this->decide($checks);
    }

    /**
     * @param  array<string, array{state: VerificationState, policy: VerifierPolicy, failure_action: FailureAction}>  $checks
     * @param  array<string, mixed>  $report
     */
    private function includeFalseGreen(array &$checks, array $report): void
    {
        if (! array_key_exists('false_green', $report) && ! (bool) config('molly.false_green.enabled', false)) {
            return;
        }

        $checks['false_green'] = [
            'state' => $this->falseGreenState(is_array($report['false_green'] ?? null) ? $report['false_green'] : null),
            'policy' => $this->policy('false_green'),
            'failure_action' => $this->failureAction('false_green', FailureAction::Fail),
        ];
    }

    /**
     * @param  array<string, array{state: VerificationState, policy: VerifierPolicy, failure_action: FailureAction}>  $checks
     * @return array{completed: bool, outcomes: array<string, array{state: string, policy: string, failure_action: string}>, blockers: list<string>}
     */
    private function decide(array $checks): array
    {
        $decision = $this->completion->evaluate($checks);
        foreach ($decision['outcomes'] as $name => $outcome) {
            $decision['outcomes'][$name]['failure_action'] = $checks[$name]['failure_action']->value;
        }

        return $decision;
    }

    /** @param  array<string, mixed>|null  $probe */
    private function falseGreenState(?array $probe): VerificationState
    {
        if ($probe === null) {
            return VerificationState::NotRun;
        }

        $state = VerificationState::tryFrom((string) ($probe['state'] ?? ''));
        if ($state !== null) {
            return $state;
        }

        return match ($probe['status'] ?? null) {
            'meaningful' => VerificationState::Pass,
            'false_green' => VerificationState::Fail,
            'inconclusive' => VerificationState::ReviewRequired,
            'not_run' => VerificationState::NotRun,
            default => VerificationState::ReviewRequired,
        };
    }

    /** @param  array<string, mixed>  $report */
    private function pestTerminatedState(array $report): VerificationState
    {
        if (! is_array($report['verification'] ?? null)) {
            return VerificationState::NotRun;
        }

        return $this->pestState($report['verification']);
    }

    /** @param  array<string, mixed>  $report */
    private function tarpitTerminatedState(array $report): VerificationState
    {
        $review = $report['review'] ?? null;
        if (! is_array($review) || ! isset($review['checks']) || ! is_array($review['checks'])) {
            return VerificationState::NotRun;
        }

        return $this->review->passed($review, null)
            ? VerificationState::Pass
            : VerificationState::Fail;
    }

    /** @param  array<string, mixed>  $report */
    private function parallelJoinTerminatedState(array $report): VerificationState
    {
        $branches = $report['branches'] ?? null;
        if (! is_array($branches) || $branches === []) {
            return VerificationState::NotRun;
        }

        return $this->branchesPassed($branches)
            ? VerificationState::Pass
            : VerificationState::Fail;
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

    /** @param  array<string, mixed>  $verification */
    private function pestState(array $verification): VerificationState
    {
        $state = VerificationState::fromObserved($verification['status'] ?? null);
        if ($state === VerificationState::Pass && ((int) ($verification['assertions'] ?? 0) < 1 || ($verification['identified_required_test'] ?? false) !== true)) {
            return VerificationState::Fail;
        }

        return $state;
    }

    private function policy(string $name): VerifierPolicy
    {
        $policy = VerifierPolicy::tryFrom((string) config('molly.verification.'.$name, 'required'));

        if ($policy === null) {
            throw new RuntimeException('VERIFICATION_POLICY_INVALID: Choose required or advisory for '.$name.'.');
        }

        return $policy;
    }

    private function failureAction(string $name, FailureAction $default): FailureAction
    {
        $configured = config('molly.verification_actions.'.$name);
        if ($configured === null) {
            return $default;
        }

        $action = FailureAction::tryFrom((string) $configured);
        if ($action === null) {
            throw new RuntimeException('VERIFICATION_ACTION_INVALID: Choose retry, fail, or warn for '.$name.'.');
        }

        return $action;
    }
}
