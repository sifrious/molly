<?php

namespace Sifrious\Molly\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;
use Sifrious\Molly\Seams\InstructionPacks;

#[Description('Read the effective complete instruction pack in the MCP host application, including source paths, versions, contents and hashes.')]
#[MimeType('application/json')]
class SeamPackResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('molly://seams/{package}/{seam}');
    }

    public function handle(Request $request, InstructionPacks $packs): Response
    {
        return Response::json($packs->inspect(base_path(), $request->get('package').'/'.$request->get('seam')));
    }
}
