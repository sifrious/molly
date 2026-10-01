<?php

namespace Sifrious\Molly\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Sifrious\Molly\Actions\SeamWorkflow;
use Sifrious\Molly\Redaction\SecretRedactor;
use Sifrious\Molly\Seams\SeamError;

#[Name('molly_seam')]
#[Description('Read editable instruction packs, validate explicit behavior, create frozen plan revisions, preview or write deterministic tests, and queue the next permitted verification step. Uses the same actions as molly:seam. Mutations require a saved revision; write and advance require its reviewed digest. Advance returns a durable run ID. Read status after reconnecting; reuse the request key to avoid duplicate work. This tool cannot approve a test lock or accept a client-supplied passed result. A person uses molly:lock-test --approve.')]
#[IsDestructive]
class MollySeam extends Tool
{
    public function handle(Request $request, SeamWorkflow $workflow): ResponseFactory
    {
        try {
            return Response::structured($workflow->handle($request->all()));
        } catch (\Throwable $exception) {
            $error = app(SecretRedactor::class)->value(SeamError::from($exception)->toArray());

            return Response::make(Response::error($error['message']))->withStructuredContent(['error' => $error]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = ['operation' => $schema->string()->enum(SeamWorkflow::OPERATIONS)->required(),
            'contract' => $schema->object()->description('Explicit state, cases, outcomes and reviewed negative control matching input.schema.json.')];
        foreach (['workspace', 'seam', 'plan', 'revision', 'digest', 'step', 'key', 'actor', 'candidate_digest', 'evidence_digest', 'recipient'] as $name) {
            $fields[$name] = $schema->string()->description($name === 'key' ? 'Reuse this idempotency key only for the same transition.' : $name);
        }

        return $fields;
    }
}
