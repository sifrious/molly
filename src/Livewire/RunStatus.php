<?php

namespace Sifrious\Molly\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Sifrious\Molly\Actions\ShowRun;
use Sifrious\Molly\Http\LocalUi;

class RunStatus extends Component
{
    #[Locked]
    public string $runId;

    public function render(): View
    {
        app(LocalUi::class)->authorize(request());
        $run = app(ShowRun::class)->handle($this->runId);
        abort_if($run === null, 404);

        return view('molly::run-status', ['run' => $run]);
    }
}
