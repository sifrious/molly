<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Classification\ChoiceClassifier;
use Sifrious\Molly\Classification\JevGate;
use Throwable;

class EvaluateWithTypeSafe
{
    public function __construct(private JevGate $gate, private ChoiceClassifier $classifier) {}

    /** @param array<string, mixed> $evidence
     * @return array{status: string, next_action: ?string, confidence: ?float, answers: array, reason: string, provider: string, model: ?string}
     */
    public function handle(array $evidence): array
    {
        $state = array_intersect_key($evidence, array_flip(['prompt', 'verification', 'review']));
        $criteria = [
            'continue' => 'The evidence supports continuing the normal loop. This does not override failed checks.',
            'retry' => 'Another bounded attempt can address the reported failures.',
            'stop' => 'Stop further attempts because continuing would not help.',
            'needs_review' => 'A person must inspect ambiguous or insufficient evidence.',
        ];

        return $this->evaluate($state, 'next_action', $criteria, config('molly.jev.instructions'),
            is_string($state['prompt'] ?? null) && is_array($state['verification'] ?? null) && is_array($state['review'] ?? null));
    }

    /** @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    public function planning(array $evidence): array
    {
        $state = array_intersect_key($evidence, array_flip(['description', 'answers', 'guide_version', 'sources']));

        return $this->evaluate($state, 'focus', [
            'outcome' => 'Clarify the user outcome and exclusions.',
            'state' => 'Distinguish essential records from derived state.',
            'laravel' => 'Prefer existing Laravel facilities and application behavior.',
            'boundaries' => 'Justify new layers against concrete requirements.',
            'verification' => 'Specify acceptance tests and complexity evidence.',
        ], 'Which planning area most needs further review under the supplied Tarpit and Laravel sources?',
            is_string($state['description'] ?? null) && is_array($state['answers'] ?? null)
            && is_string($state['guide_version'] ?? null) && is_array($state['sources'] ?? null));
    }

    /** @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    public function commit(array $evidence): array
    {
        $state = array_intersect_key($evidence, array_flip(['prompt', 'verification', 'review']));

        return $this->evaluate($state, 'next_action', [
            'continue' => 'No semantic code-quality blocker identified in the supplied diff.',
            'retry' => 'Revise the commit to address accidental complexity or duplicated framework behavior.',
            'stop' => 'Do not proceed with this approach because a concrete requirement contradicts it.',
            'needs_review' => 'The supplied evidence is insufficient or ambiguous for a quality judgment.',
        ], 'Evaluate this PHP diff against the cited Tarpit and Laravel guidance. Look for unnecessary state, indirection, and duplicated framework behavior. Assess semantic quality only. Tests marked not_run are not passing evidence, but do not alone require an uncertain semantic judgment. Never claim tests ran.',
            is_string($state['prompt'] ?? null) && is_array($state['verification'] ?? null)
            && is_array($state['review'] ?? null) && is_string($state['review']['diff'] ?? null)
            && is_array($state['review']['citations'] ?? null));
    }

    /**
     * One evaluation path for every question. The result shape and reason
     * codes are the accepted contract in docs/reference/configuration.md
     * ("Jev states"); every transport projects this array unchanged.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, string>  $criteria
     * @return array<string, mixed>
     */
    private function evaluate(array $state, string $question, array $criteria, mixed $instructions, bool $valid): array
    {
        $config = config('molly.jev', []);
        $model = $config['model'] ?? null;
        $result = [
            'status' => 'needs_review',
            $question => $question === 'focus' ? null : 'needs_review',
            'confidence' => null,
            'answers' => [],
            'reason' => 'invalid_config',
            'provider' => 'typesafe',
            'model' => is_string($model) ? $model : null,
        ];

        $gate = $this->gate->status();
        if ($gate === JevGate::DISABLED || $gate === JevGate::UNAVAILABLE) {
            return array_replace($result, ['status' => $gate, $question => null, 'reason' => $this->gate->reason()]);
        }
        if ($gate !== JevGate::READY) {
            return $result;
        }

        $threshold = $config['confidence_threshold'] ?? null;
        $timeout = $config['timeout'] ?? null;
        if (! is_string($model) || trim($model) === '' || strlen($model) > 128
            || ! $this->probability($threshold) || ! is_int($timeout) || $timeout < 1 || $timeout > 120
            || ! is_string($instructions) || trim($instructions) === '' || strlen($instructions) > 4096) {
            return $result;
        }

        $encoded = json_encode($state);
        if (! $valid || $encoded === false || strlen($encoded) > 32768) {
            return array_replace($result, ['reason' => 'invalid_evidence']);
        }

        try {
            $answer = $this->classifier->choose($state, $question, $instructions, $criteria, $model, $timeout);
        } catch (Throwable) {
            return array_replace($result, ['reason' => 'provider_error']);
        }

        if ($answer === null || ! array_key_exists($answer->choice, $criteria)
            || ! $this->probability($answer->confidence) || count($answer->probabilities) !== count($criteria)) {
            return array_replace($result, ['reason' => 'invalid_answer']);
        }

        foreach ($criteria as $option => $description) {
            if (! $this->probability($answer->probabilities[$option] ?? null)) {
                return array_replace($result, ['reason' => 'invalid_answer']);
            }
        }

        if (abs(array_sum($answer->probabilities) - 1) > 0.01
            || $answer->probabilities[$answer->choice] < max($answer->probabilities)) {
            return array_replace($result, ['reason' => 'invalid_answer']);
        }

        $confidence = (float) $answer->confidence;
        $result = array_replace($result, [
            'model' => $answer->model ?? $model,
            'confidence' => $confidence,
            'answers' => [$question => [
                'type' => 'choice',
                'choice' => $answer->choice,
                'confidence' => $confidence,
                'probabilities' => $answer->probabilities,
            ]],
        ]);

        if ($confidence < $threshold) {
            return array_replace($result, ['reason' => 'low_confidence']);
        }

        return array_replace($result, ['status' => 'evaluated', $question => $answer->choice, 'reason' => 'evaluated']);
    }

    private function probability(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1;
    }
}
