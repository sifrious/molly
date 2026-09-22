<?php

use Sifrious\Molly\Console\RunReport;

it('groups RunReport output through private evidence-section methods', function () {
    $reflection = new ReflectionClass(RunReport::class);
    foreach ([
        'showBranches', 'showRequiredChecks', 'showChanges', 'showSnapshots', 'showVerification',
        'showTarpit', 'showMeasurements', 'showAdvice', 'showErrors', 'showOutcome',
    ] as $method) {
        expect($reflection->hasMethod($method))->toBeTrue()
            ->and($reflection->getMethod($method)->isPrivate())->toBeTrue();
    }

    $source = file_get_contents(dirname(__DIR__, 3).'/src/Console/RunReport.php');
    expect($source)->toContain('$this->showRequiredChecks($report)')
        ->and($source)->toContain('$this->showChanges($report)')
        ->and($source)->toContain('$this->showVerification($report, $verbose)')
        ->and($source)->toContain('$this->showTarpit($report)')
        ->and($source)->toContain('$this->showAdvice($report)')
        ->and($source)->toContain('$this->showErrors($report)')
        ->and($source)->toContain('$this->showOutcome($run->status)')
        ->and($source)->not->toContain('DecideRunCompletion');
});
