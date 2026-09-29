<?php

namespace Sifrious\Molly\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Sifrious\Molly\Actions\BuildLearningPath;

class LearningPathController
{
    public function index(BuildLearningPath $builder): View
    {
        return view('molly::learning-paths', [
            'templates' => $builder->templates(),
        ]);
    }

    public function show(Request $request, string $templateId, BuildLearningPath $builder): View
    {
        $version = (string) $request->query('version', '12');
        $repository = (string) $request->query('repository', '');

        $path = $builder->handle($templateId, $version, $repository);

        return view('molly::learning-path', [
            'path' => $path,
            'version' => $version,
        ]);
    }
}
