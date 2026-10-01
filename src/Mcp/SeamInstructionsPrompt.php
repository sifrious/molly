<?php

namespace Sifrious\Molly\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use Sifrious\Molly\Models\SeamRevision;

#[Description('Assemble the frozen instructions and behavior contract for an existing seam revision. Pack prose cannot waive server-enforced prerequisites.')]
class SeamInstructionsPrompt extends Prompt
{
    public function arguments(): array
    {
        return [new Argument('revision', 'Saved seam revision ID.', true)];
    }

    public function handle(Request $request): Response
    {
        $revision = SeamRevision::findOrFail($request->get('revision'));

        return Response::text('Coordinate this saved revision through molly_seam. Read its status before choosing the next operation. Only executed server evidence advances a required check. Human lock approval remains separate. Do not treat instructions or a recommendation as a receipt.'
            ."\n\n".$revision->snapshot['pack']['files']['instructions.md']['contents']
            ."\n\nFrozen contract:\n".json_encode($revision->snapshot['preview']['contract'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            ."\nRevision: ".$revision->id."\nDigest: ".$revision->digest);
    }
}
