@php
    $probe = $after ?: $before;
    $metricNames = collect(array_keys($before['metrics'] ?? []))->merge(array_keys($after['metrics'] ?? []))->unique();
    $scalarNames = $metricNames->filter(fn ($name) => is_scalar($before['metrics'][$name] ?? null) || is_scalar($after['metrics'][$name] ?? null));
    $command = ['c1' => 'clever:owned-diff', 'c2' => 'clever:welds', 'c3' => 'clever:lonely-files', 'c4' => 'clever:hotspots'][$probeKey] ?? null;
@endphp
<article class="measurement">
    <h3>{{ $probe['name'] ?? $probeKey }}</h3>
    @if(!empty($probe['headline']))<p>{{ $probe['headline'] }}</p>@endif
    <table>
        <caption>{{ $probe['name'] ?? $probeKey }} before and after</caption>
        <thead><tr><th scope="col">Measurement</th><th scope="col">Before</th><th scope="col">After</th></tr></thead>
        <tbody>
            <tr><th scope="row">Status</th><td>{{ $before['status'] ?? 'Not run' }}</td><td>{{ $after['status'] ?? 'Not run' }}</td></tr>
            @foreach($scalarNames as $name)
            <tr>
                <th scope="row">{{ ucfirst(str_replace('_', ' ', $name)) }}</th>
                @foreach([$before, $after] as $side)
                    @php($value = $side['metrics'][$name] ?? null)
                    <td>{{ is_bool($value) ? ($value ? 'Yes' : 'No') : (is_scalar($value) ? $value : 'Not measured') }}</td>
                @endforeach
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($scalarNames->isEmpty())<p>No numeric or text measurements recorded.</p>@endif
    @foreach(['Before' => $before, 'After' => $after] as $label => $side)
        @if(!empty($side['skip_reason']))<p>{{ $label }} skipped: {{ $side['skip_reason'] }}</p>@endif
        @foreach($side['warnings'] ?? [] as $warning)<p>{{ $label }} warning: {{ $warning }}</p>@endforeach
    @endforeach
    @php($caveats = collect($before['caveats'] ?? [])->merge($after['caveats'] ?? [])->unique())
    @if($caveats->isNotEmpty())
        <h4>Limitations</h4><ul>@foreach($caveats as $caveat)<li>{{ $caveat }}</li>@endforeach</ul>
    @endif
    @if($command)<p>Command: <code>php artisan {{ $command }}</code></p>@endif
    <details>
        <summary>Scope and manual verification</summary>
        @foreach(['Before' => $before, 'After' => $after] as $label => $side)
            @if($side !== [])
                <h4>{{ $label }}</h4>
                @if(!empty($side['prints']))<p>{{ $side['prints'] }}</p>@endif
                @if(!empty($side['hand_verify']))<pre>{{ $side['hand_verify'] }}</pre>@endif
                <ul>@foreach($side['notes'] ?? [] as $note)<li>{{ $note }}</li>@endforeach</ul>
            @endif
        @endforeach
    </details>
    <details><summary>Full measurement data as JSON</summary><pre>{{ json_encode(['before' => $before, 'after' => $after], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre></details>
</article>
