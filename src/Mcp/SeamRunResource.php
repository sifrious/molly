<?php

namespace Sifrious\Molly\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;
use Sifrious\Molly\Actions\InspectSeamRun;

#[Description('Read saved seam attempts, required checks, blockers, handoff identity and hashed evidence references.')]
#[MimeType('application/json')]
class SeamRunResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('molly://seam-runs/{revision}');
    }

    public function handle(Request $request, InspectSeamRun $inspect): Response
    {
        return Response::json($inspect->handle($request->get('revision')));
    }
}
