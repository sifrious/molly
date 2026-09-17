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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;
use Sifrious\Molly\PlanningGuide;

#[Name('molly_guide')]
#[Description('Read the offline planning graph, or a source passage with its citation, pinned revision, and digest. No network request is made.')]
#[IsReadOnly]
class MollyGuide extends Tool
{
    public function handle(Request $request, PlanningGuide $guide): Response|ResponseFactory
    {
        $data = $request->validate([
            'operation' => ['required', 'in:graph,source'],
            'id' => ['required_if:operation,source', 'string', 'max:100'],
        ]);
        try {
            return Response::structured($data['operation'] === 'graph' ? $guide->graph() : $guide->source($data['id']));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->enum(['graph', 'source'])->required(),
            'id' => $schema->string()->description('Source ID from the graph; required for source.'),
        ];
    }
}
