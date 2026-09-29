<div>
    <div style="display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1.5rem;">
        @foreach([
            'changed_directly' => 'Changed directly',
            'affected_context' => 'Affected context',
            'tests_contracts' => 'Tests and contracts',
            'unknown_impact' => 'Unknown impact',
            'visual_changes' => 'Visual changes',
        ] as $key => $label)
            <button
                wire:click="selectSection('{{ $key }}')"
                style="font: inherit; padding: .45rem .8rem; border-radius: .3rem; cursor: pointer; border: 1px solid {{ $activeSection === $key ? '#174f40' : '#606d61' }}; background: {{ $activeSection === $key ? '#174f40' : 'white' }}; color: {{ $activeSection === $key ? 'white' : '#17202a' }};"
            >
                {{ $label }}
                <span style="font-size: .85em; opacity: .7;">({{ count($delta->sections[$key]) }})</span>
            </button>
        @endforeach
    </div>

    @if($delta->beforeRevision || $delta->afterRevision)
    <dl style="margin-bottom: 1rem;">
        @if($delta->beforeRevision)<dt style="display: inline; font-weight: 650;">Before:</dt><dd style="display: inline; margin-left: .25rem;"><code>{{ $delta->beforeRevision }}</code></dd><br>@endif
        @if($delta->afterRevision)<dt style="display: inline; font-weight: 650;">After:</dt><dd style="display: inline; margin-left: .25rem;"><code>{{ $delta->afterRevision }}</code></dd>@endif
    </dl>
    @endif

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: start;">
        <div>
            <h3 style="margin-top: 0;">{{ ['changed_directly' => 'Changed directly', 'affected_context' => 'Affected context', 'tests_contracts' => 'Tests and contracts', 'unknown_impact' => 'Unknown impact', 'visual_changes' => 'Visual changes'][$activeSection] }}</h3>
            @if($activeSection === 'affected_context')
                <p class="hint">Affected context does not mean broken. These items reference changed nodes but have not been proven faulty.</p>
            @elseif($activeSection === 'unknown_impact')
                <p class="hint">Unknown impact stays unknown. Molly does not invent authoritative edges for unproven relationships.</p>
            @endif
            @forelse($items as $item)
                <button
                    wire:click="selectItem('{{ $item->id }}')"
                    style="display: block; width: 100%; text-align: left; font: inherit; padding: .6rem .8rem; margin-bottom: .5rem; border-radius: .3rem; cursor: pointer; border: 1px solid {{ $selected && $selected->id === $item->id ? '#174f40' : '#bac2ba' }}; background: {{ $selected && $selected->id === $item->id ? '#eef6ee' : 'white' }};"
                >
                    <span style="display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;">
                        <span style="font-weight: 650;">{{ $item->key }}</span>
                        <span style="font-size: .8rem; padding: .1rem .4rem; border-radius: .2rem; background: {{ match($item->change->value) { 'added' => '#c6efce', 'removed' => '#ffc7ce', 'changed' => '#fff2cc' } }}; color: {{ match($item->change->value) { 'added' => '#1a5928', 'removed' => '#9a2518', 'changed' => '#6b5300' } }};">{{ $item->change->value }}</span>
                        <span style="font-size: .8rem; padding: .1rem .4rem; border-radius: .2rem; background: {{ match($item->freshness->value) { 'current' => '#c6efce', 'stale' => '#fff2cc', 'partially_updated' => '#fff2cc', 'unavailable' => '#ffc7ce', 'unknown' => '#e0e0e0' } }}; color: #17202a;">{{ str_replace('_', ' ', $item->freshness->value) }}</span>
                    </span>
                    <span style="display: block; font-size: .85rem; color: #46534b; margin-top: .2rem;">{{ $item->type }}@if($item->sourcePath) · <code>{{ $item->sourcePath }}</code>@endif</span>
                </button>
            @empty
                <p>No items in this section.</p>
            @endforelse
        </div>

        <div>
            @if($selected)
                <div style="border: 1px solid #bac2ba; border-radius: .3rem; background: white; padding: 1rem;">
                    <h3 style="margin-top: 0;">{{ $selected->key }}</h3>
                    <dl>
                        <dt>ID</dt><dd><code>{{ $selected->id }}</code></dd>
                        <dt>Type</dt><dd>{{ $selected->type }}</dd>
                        <dt>Change</dt><dd>
                            <span style="padding: .1rem .4rem; border-radius: .2rem; background: {{ match($selected->change->value) { 'added' => '#c6efce', 'removed' => '#ffc7ce', 'changed' => '#fff2cc' } }};">{{ $selected->change->value }}</span>
                        </dd>
                        <dt>Freshness</dt><dd>
                            <span style="padding: .1rem .4rem; border-radius: .2rem; background: {{ match($selected->freshness->value) { 'current' => '#c6efce', 'stale' => '#fff2cc', 'partially_updated' => '#fff2cc', 'unavailable' => '#ffc7ce', 'unknown' => '#e0e0e0' } }};">{{ str_replace('_', ' ', $selected->freshness->value) }}</span>
                        </dd>
                        <dt>Verification</dt><dd>
                            <span style="padding: .1rem .4rem; border-radius: .2rem; background: {{ match($selected->verification->value) { 'verified' => '#c6efce', 'review_required' => '#fff2cc', 'blocked' => '#ffc7ce', 'unresolved' => '#e0e0e0' } }};">{{ str_replace('_', ' ', $selected->verification->value) }}</span>
                        </dd>
                        @if($selected->sourcePath)
                            <dt>Source path</dt><dd><code>{{ $selected->sourcePath }}</code></dd>
                        @endif
                        @if($selected->sourceUrl)
                            <dt>Source URL</dt><dd><a href="{{ $selected->sourceUrl }}">{{ $selected->sourceUrl }}</a></dd>
                        @endif
                    </dl>

                    @if($selected->fields !== [])
                        <h4>Fields</h4>
                        @foreach($selected->fields as $fieldKey => $fieldValue)
                            <dl>
                                <dt>{{ $fieldKey }}</dt>
                                <dd>
                                    @if(is_array($fieldValue))
                                        <pre style="margin: .25rem 0;">{{ json_encode($fieldValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    @else
                                        {{ $fieldValue }}
                                    @endif
                                </dd>
                            </dl>
                        @endforeach

                        @if(isset($selected->fields['before']) && isset($selected->fields['after']))
                            <h4>Before / After</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div>
                                    <p style="font-weight: 650; margin-bottom: .25rem;">Before</p>
                                    <pre style="margin: 0; border-color: #ffc7ce;">{{ json_encode($selected->fields['before'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </div>
                                <div>
                                    <p style="font-weight: 650; margin-bottom: .25rem;">After</p>
                                    <pre style="margin: 0; border-color: #c6efce;">{{ json_encode($selected->fields['after'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </div>
                            </div>
                        @endif
                    @endif
                </div>
            @else
                <div style="border: 1px solid #bac2ba; border-radius: .3rem; background: white; padding: 1rem; color: #46534b;">
                    <p>Select an item to see its details, fields, and before/after state.</p>
                </div>
            @endif
        </div>
    </div>

    <details style="margin-top: 1.5rem;">
        <summary>Provenance</summary>
        <dl>
            <dt>Pack hash</dt><dd><code>{{ $delta->provenance->packHash }}</code></dd>
            <dt>Package</dt><dd>{{ $delta->provenance->package }}</dd>
            @if($delta->provenance->exactVersion)<dt>Version</dt><dd>{{ $delta->provenance->exactVersion }}</dd>@endif
            @if($delta->provenance->repositoryRevision)<dt>Repository revision</dt><dd><code>{{ $delta->provenance->repositoryRevision }}</code></dd>@endif
            @if($delta->provenance->documentationRevision)<dt>Documentation revision</dt><dd><code>{{ $delta->provenance->documentationRevision }}</code></dd>@endif
        </dl>
    </details>
</div>
