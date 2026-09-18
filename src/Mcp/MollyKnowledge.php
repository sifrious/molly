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
use Sifrious\Molly\Actions\QueryKnowledgeGraph;
use Throwable;

#[Name('molly_knowledge')]
#[Description('Read a small, version-matched neighborhood from Molly\'s local Laravel, NativePHP, or tarpit knowledge graph. Results contain source provenance for every node and relationship. Index with molly:knowledge:index laravel, nativephp, or tarpit. NativePHP Desktop v2 and Mobile v4 stay separate. Tarpit notes are not a quality score. Project graphs are separate and use molly:project:index.')]
#[IsReadOnly]
final class MollyKnowledge extends Tool
{
    public function handle(Request $request, QueryKnowledgeGraph $query): Response|ResponseFactory
    {
        $data = $request->validate([
            'concept' => ['required', 'string', 'max:200'],
            'namespace' => ['sometimes', 'string', 'in:laravel,nativephp,tarpit'],
            'version' => ['sometimes', 'string', 'regex:/\\A(?:\\d+|desktop-2|mobile-4|notes-\\d{4}-\\d{2}-\\d{2})\\z/'],
            'depth' => ['sometimes', 'integer', 'between:0,3'],
            'limit' => ['sometimes', 'integer', 'between:1,40'],
            'relations' => ['sometimes', 'array', 'max:20'],
            'relations.*' => ['string', 'regex:/\\A[a-z][a-z0-9_]{0,49}\\z/'],
        ]);

        try {
            return Response::structured($query->handle(
                $data['concept'], $data['version'] ?? null, $data['depth'] ?? 2,
                $data['limit'] ?? 20, $data['relations'] ?? [], $data['namespace'] ?? 'laravel',
            ));
        } catch (Throwable $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'concept' => $schema->string()->description('Concept or symbol, such as Queue, Desktop, Mobile, or Tarpit.')->required(),
            'namespace' => $schema->string()->description('laravel, nativephp, or tarpit. Defaults to laravel.'),
            'version' => $schema->string()->description('Laravel major version, NativePHP desktop-2 or mobile-4, or tarpit notes-YYYY-MM-DD. Molly detects Laravel and tarpit versions when omitted.'),
            'depth' => $schema->integer()->min(0)->max(3)->description('Relationship depth. Defaults to 2.'),
            'limit' => $schema->integer()->min(1)->max(40)->description('Maximum nodes. Defaults to 20.'),
            'relations' => $schema->array()->items($schema->string())->max(20)->description('Optional relationship names to include.'),
        ];
    }
}
