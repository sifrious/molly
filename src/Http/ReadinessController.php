<?php

namespace Sifrious\Molly\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Sifrious\Molly\Actions\CheckEnvironment;

class ReadinessController
{
    public function show(Request $request, CheckEnvironment $check): View
    {
        $workspace = (string) $request->query('workspace', base_path());
        if (! is_dir($workspace)) {
            return view('molly::readiness', [
                'workspace' => $workspace,
                'report' => null,
                'error' => 'WORKSPACE_INVALID: '.$workspace.' is not an existing directory. Choose the Laravel project root.',
            ]);
        }

        return view('molly::readiness', [
            'workspace' => $workspace,
            'report' => $check->handle($workspace, probeDatabaseWrite: false),
            'error' => null,
        ]);
    }
}
