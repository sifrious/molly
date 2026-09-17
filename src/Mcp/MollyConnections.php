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
use Sifrious\Molly\Actions\FindTaskConnections;

#[Name('molly_connections')]
#[Description('Read Amp threads linked to a task nickname or UUID, including prior exact associations. By default, read current Amp executor status. stored=true reads only saved user links and leaves connection state unknown. No work starts, no target is selected, and an Amp executor is not a verified Orb identity.')]
#[IsReadOnly]
class MollyConnections extends Tool
{
    public function handle(Request $request, FindTaskConnections $find): Response|ResponseFactory
    {
        $data = $request->validate(['task' => ['required', 'string', 'max:100'], 'stored' => ['sometimes', 'boolean']]);
        try {
            return Response::structured($find->handle($data['task'], ! ($data['stored'] ?? false)));
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('Saved task nickname or UUID.')->required(),
            'stored' => $schema->boolean()->description('Skip the Amp status probe and read saved associations only. Defaults to false.'),
        ];
    }
}
