<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Sifrious\Molly\Agents\AmpResponse;
use Sifrious\Molly\Agents\LocalOllama;
use Sifrious\Molly\Agents\TarpitReviewer;

class ReviewChanges
{
    public function __construct(private AmpResponse $ampResponse) {}

    /**
     * @param  array<string, string|null>  $before
     * @param  array<string, string>  $after
     * @return array{checks: array<string, array{status: string, evidence: string}>, findings: list<array{code: string, classification: string, severity: string, path: string, line: int, problem: string, recommendation: string}>}
     */
    public function handle(string $prompt, array $before, array $after): array
    {
        $input = json_encode(['task' => $prompt, 'before' => $before, 'after' => $after], JSON_THROW_ON_ERROR);
        if (config('molly.agent', 'ollama') === 'amp') {
            $result = $this->ampResponse->prompt(new TarpitReviewer, $input);
        } elseif (config('molly.agent', 'ollama') === 'ollama') {
            LocalOllama::validate();
            $response = TarpitReviewer::make()->prompt(
                $input, provider: 'ollama', model: config('molly.model'), timeout: config('molly.timeout'),
            );
            $result = $response instanceof StructuredAgentResponse ? $response->toArray() : [];
        } else {
            throw new RuntimeException('AGENT_INVALID: Choose amp or ollama for molly.agent.');
        }
        $this->validate($result, $after);

        return ['checks' => $result['checks'], 'findings' => $result['findings']];
    }

    /**
     * @param  array<string, mixed>  $review
     * @param  array<string, string>|null  $after
     */
    public function passed(array $review, ?array $after = null): bool
    {
        return $this->hasValidStructure($review)
            && ($after === null || $this->findingsMatchFiles($review, $after))
            && ! collect($review['findings'])->contains(fn (array $finding): bool => $finding['severity'] === 'blocking');
    }

    /** @param array<string, mixed> $result
     * @param  array<string, string>  $after
     */
    private function validate(array $result, array $after): void
    {
        if (! $this->hasValidStructure($result) || ! $this->findingsMatchFiles($result, $after)) {
            $this->invalid();
        }
    }

    /** @param array<string, mixed> $result */
    private function hasValidStructure(array $result): bool
    {
        $rules = [
            'checks' => ['required', 'array:A,B,C,D,E,F,G'],
            'findings' => ['present', 'array', 'list'],
            'findings.*' => ['array:code,classification,severity,path,line,problem,recommendation'],
            'findings.*.code' => ['required', 'in:A,B,C,D,E,F,G'],
            'findings.*.classification' => ['required', 'in:essential,accidental,pragmatic'],
            'findings.*.severity' => ['required', 'in:blocking,warning'],
            'findings.*.path' => ['required', 'string'],
            'findings.*.line' => ['required', 'integer', 'min:1'],
            'findings.*.problem' => ['required', 'string'],
            'findings.*.recommendation' => ['required', 'string'],
        ];
        foreach (range('A', 'G') as $code) {
            $rules["checks.$code"] = ['required', 'array:status,evidence'];
            $rules["checks.$code.status"] = ['required', 'in:clean,findings'];
            $rules["checks.$code.evidence"] = ['required', 'string'];
        }
        if (Validator::make($result, $rules)->fails()) {
            return false;
        }
        foreach ($result['findings'] as $finding) {
            if (! is_int($finding['line'])) {
                return false;
            }
        }
        foreach (range('A', 'G') as $code) {
            $hasFindings = in_array($code, array_column($result['findings'], 'code'), true);
            if (($result['checks'][$code]['status'] === 'findings') !== $hasFindings) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $result
     * @param  array<string, string>  $after
     */
    private function findingsMatchFiles(array $result, array $after): bool
    {
        foreach ($result['findings'] as $finding) {
            if (! array_key_exists($finding['path'], $after)
                || $finding['line'] > substr_count($after[$finding['path']], "\n") + 1
                || ($finding['severity'] === 'blocking' && $finding['classification'] !== 'accidental')) {
                return false;
            }
        }

        return true;
    }

    private function invalid(): never
    {
        throw new RuntimeException('REVIEW_INVALID: The model did not return a complete, consistent review of the supplied files.');
    }
}
