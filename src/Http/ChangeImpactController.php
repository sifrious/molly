<?php

namespace Sifrious\Molly\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Sifrious\Molly\GraphDelta\DeltaProvenance;
use Sifrious\Molly\GraphDelta\GraphDeltaView;

class ChangeImpactController
{
    public function show(Request $request): View
    {
        $deltaJson = (string) $request->query('delta', '');

        if ($deltaJson === '') {
            return view('molly::change-impact-page', [
                'deltaJson' => $this->placeholderJson(),
            ]);
        }

        GraphDeltaView::fromJson($deltaJson);

        return view('molly::change-impact-page', [
            'deltaJson' => $deltaJson,
        ]);
    }

    private function placeholderJson(): string
    {
        $fixture = __DIR__.'/../../tests/Fixtures/GraphDelta/role-feature-delta.json';

        if (file_exists($fixture)) {
            return file_get_contents($fixture);
        }

        return (new GraphDeltaView(
            version: GraphDeltaView::VERSION,
            provenance: new DeltaProvenance(
                packHash: 'placeholder',
                package: 'placeholder',
            ),
            sections: [
                'changed_directly' => [],
                'affected_context' => [],
                'tests_contracts' => [],
                'unknown_impact' => [],
                'visual_changes' => [],
            ],
        ))->toJson();
    }
}
