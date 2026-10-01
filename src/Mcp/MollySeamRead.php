<?php

namespace Sifrious\Molly\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Sifrious\Molly\Actions\SeamWorkflow;
use Sifrious\Molly\Redaction\SecretRedactor;
use Sifrious\Molly\Seams\SeamError;

#[Name('molly_seam_read')]
#[Description('Read or validate seam instructions and saved evidence without creating plans, writing files, queuing work or approving changes. Use molly_seam for mutations.')]
#[IsReadOnly]
class MollySeamRead extends Tool
{
    public const OPERATIONS = ['list', 'inspect', 'compare', 'validate', 'preview', 'status', 'report'];

    public function handle(Request $request, SeamWorkflow $workflow): ResponseFactory
    {
        try {
            if (! in_array($request->get('operation'), self::OPERATIONS, true)) {
                throw new SeamError('PLAN_STEP_NOT_ALLOWED', 'This read-only tool cannot change a seam plan.', 'operation', self::OPERATIONS, $request->get('operation'));
            }

            return Response::structured($workflow->handle($request->all()));
        } catch (\Throwable $exception) {
            $error = app(SecretRedactor::class)->value(SeamError::from($exception)->toArray());

            return Response::make(Response::error($error['message']))->withStructuredContent(['error' => $error]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [...app(MollySeam::class)->schema($schema), 'operation' => $schema->string()->enum(self::OPERATIONS)->required()];
    }
}
