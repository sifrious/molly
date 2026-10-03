<?php

namespace Sifrious\Molly\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Sifrious\Molly\Acceptance\VerificationMode;
use Sifrious\Molly\Actions\VerifyMolly;

#[Name('molly_verify')]
#[Description('Inspect Screen Recording and Accessibility, run the M04 Bloom verification, run it without those permissions, retry checks blocked only by a verifier permission, or read saved evidence. A missing permission is BLOCKED_VERIFIER_PERMISSION. It is not a Molly product failure. This tool does not grant macOS permissions.')]
#[IsDestructive]
class MollyVerify extends Tool
{
    public function handle(Request $request, VerifyMolly $verify): Response|ResponseFactory
    {
        $data = $request->validate([
            'operation' => ['required', 'in:inspect_permissions,run,run_permissionless,retry_native_ui,latest,check_evidence'],
            'evidence' => ['sometimes', 'string', 'max:500'],
            'candidate' => ['sometimes', 'nullable', 'string', 'max:40'],
            'check_id' => ['required_if:operation,check_evidence', 'string', 'max:20'],
            'attempt' => ['sometimes', 'integer', 'min:1'],
        ]);
        $evidence = is_string($data['evidence'] ?? null) && $data['evidence'] !== '' ? $data['evidence'] : storage_path('molly/acceptance');
        $candidate = is_string($data['candidate'] ?? null) && $data['candidate'] !== '' ? $data['candidate'] : null;
        $result = match ($data['operation']) {
            'inspect_permissions' => $verify->inspectPermissions($evidence, $candidate),
            'run' => $verify->verify(VerificationMode::Default, $evidence, $candidate),
            'run_permissionless' => $verify->verify(VerificationMode::Permissionless, $evidence, $candidate),
            'retry_native_ui' => $verify->verify(VerificationMode::RetryNativeUi, $evidence, $candidate),
            'latest' => $verify->latest($evidence),
            'check_evidence' => $verify->checkEvidence($evidence, $data['check_id'], isset($data['attempt']) ? (int) $data['attempt'] : null),
        };
        if (($result['exit_code'] ?? null) === 4 && is_string($result['reason_code'] ?? null)) {
            return Response::error($result['reason_code'].': '.$result['message']);
        }

        return Response::structured($result);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->enum([
                'inspect_permissions',
                'run',
                'run_permissionless',
                'retry_native_ui',
                'latest',
                'check_evidence',
            ])->required(),
            'evidence' => $schema->string()->description('Evidence directory. Defaults to storage/molly/acceptance.'),
            'candidate' => $schema->string()->description('40-character candidate SHA. Defaults to HEAD.'),
            'check_id' => $schema->string()->description('Check id, required for check_evidence. Example: M04.2.'),
            'attempt' => $schema->integer()->description('Attempt number for check_evidence. Defaults to the latest attempt.'),
        ];
    }
}
