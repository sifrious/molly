<?php

namespace Sifrious\Molly\Classification;

use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

/**
 * Molly's only Laravel AI classification call. The provider is always
 * Laravel AI's TypeSafe lab; credentials live in ai.providers.typesafe.
 */
final class LaravelAiChoiceClassifier implements ChoiceClassifier
{
    public function __construct(private DetectLaravelAiClassification $detect) {}

    public function available(): bool
    {
        return $this->detect->supportsChoice();
    }

    public function choose(array $state, string $question, string $instructions, array $criteria, string $model, int $timeout): ?ChoiceClassification
    {
        $response = Classification::of($state)
            ->question($question, new Choice($instructions, $criteria))
            ->timeout($timeout)
            ->classify(provider: Lab::TypeSafe, model: $model);
        $answer = isset($response[$question]) ? $response[$question] : null;
        if (! $answer instanceof ChoiceAnswer) {
            return null;
        }
        $reported = $response->meta->model ?? null;

        return new ChoiceClassification($answer->choice, $answer->probabilities, $answer->confidence, is_string($reported) ? $reported : null);
    }
}
