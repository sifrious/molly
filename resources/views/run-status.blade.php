<div @if($run->status === 'running') wire:poll.5s @endif>
    <p role="status">Run status: {{ $run->status }}@if($run->status === 'running' && !empty($run->report['phase'])). {{ $run->report['phase'] }}@endif</p>
    @if($run->status === 'running')
        <p>Refresh all evidence to read the latest saved results.</p>
        <details><summary>If progress has stopped</summary><p>A running status may remain after an interruption. Check the worker output before stopping or retrying the task.</p></details>
    @elseif($run->status === 'completed')
        <p>Task completed. Review the changed files before committing.</p>
    @endif
</div>
