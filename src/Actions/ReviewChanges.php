<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Sifrious\Molly\Agents\LocalOllama;
use Sifrious\Molly\Agents\TarpitReviewer;

class ReviewChanges
{
    /**
     * @param  array<string, string|null>  $before
     * @param  array<string, string>  $after
     * @return array{checks: array<string, array{status: string, evidence: string}>, findings: list<array{code: string, classification: string, severity: string, path: string, line: int, problem: string, recommendation: string}>}
     */
    public function handle(string $prompt, array $before, array $after): array
    {
        LocalOllama::validate();
        $response = TarpitReviewer::make()->prompt(
            json_encode(['task' => $prompt, 'before' => $before, 'after' => $after], JSON_THROW_ON_ERROR),
            provider: 'ollama', model: config('molly.model'), timeout: config('molly.timeout'),
        );
        $result = $response instanceof StructuredAgentResponse ? $response->toArray() : [];
        $this->validate($result, $after);

        return ['checks' => $result['checks'], 'findings' => $result['findings']];
    }

    /** @param array<string, mixed> $result
     * @param  array<string, string>  $after
     */
    private function validate(array $result, array $after): void
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
            $this->invalid();
        }
        $this->validateFindings($result, $after);
    }

    /** @param array<string, mixed> $result
     * @param  array<string, string>  $after
     */
    private function validateFindings(array $result, array $after): void
    {
        foreach ($result['findings'] as $finding) {
            if (! array_key_exists($finding['path'], $after)
                || ! is_int($finding['line'])
                || $finding['line'] > substr_count($after[$finding['path']], "\n") + 1
                || ($finding['severity'] === 'blocking' && $finding['classification'] !== 'accidental')) {
                $this->invalid();
            }
        }
        foreach (range('A', 'G') as $code) {
            $hasFindings = in_array($code, array_column($result['findings'], 'code'), true);
            if (($result['checks'][$code]['status'] === 'findings') !== $hasFindings) {
                $this->invalid();
            }
        }
    }

    private function invalid(): never
    {
        throw new RuntimeException('REVIEW_INVALID: The model did not return a complete, consistent review of the supplied files.');
    }
}
