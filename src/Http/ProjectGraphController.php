<?php

namespace Sifrious\Molly\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use RuntimeException;
use Sifrious\Molly\Actions\InspectProjectGraph;

class ProjectGraphController
{
    public function show(Request $request, InspectProjectGraph $inspect): View
    {
        $workspace = (string) $request->query('workspace', base_path());

        try {
            return view('molly::project-graph', $inspect->handle($workspace));
        } catch (RuntimeException $exception) {
            return view('molly::project-graph', [
                'workspace' => $workspace,
                'namespace' => 'project',
                'version' => '',
                'database' => '',
                'sources' => 0,
                'nodes' => [],
                'edges' => [],
                'blockers' => [],
                'truncated' => false,
            ])->withErrors(['workspace' => $exception->getMessage()]);
        }
    }
}
