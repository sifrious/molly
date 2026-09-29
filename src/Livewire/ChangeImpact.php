<?php

namespace Sifrious\Molly\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Sifrious\Molly\GraphDelta\GraphDeltaView;
use Sifrious\Molly\Http\LocalUi;

class ChangeImpact extends Component
{
    #[Locked]
    public string $deltaJson;

    public string $activeSection = 'changed_directly';

    public ?string $selectedItemId = null;

    public function mount(string $deltaJson): void
    {
        GraphDeltaView::fromJson($deltaJson);
        $this->deltaJson = $deltaJson;
    }

    public function selectSection(string $section): void
    {
        $this->activeSection = $section;
        $this->selectedItemId = null;
    }

    public function selectItem(?string $itemId): void
    {
        $this->selectedItemId = $itemId;
    }

    public function render(): View
    {
        app(LocalUi::class)->authorize(request());

        $delta = GraphDeltaView::fromJson($this->deltaJson);
        $items = $delta->sections[$this->activeSection] ?? [];
        $selected = null;

        if ($this->selectedItemId !== null) {
            foreach ($items as $item) {
                if ($item->id === $this->selectedItemId) {
                    $selected = $item;
                    break;
                }
            }
        }

        return view('molly::change-impact', [
            'delta' => $delta,
            'items' => $items,
            'selected' => $selected,
            'activeSection' => $this->activeSection,
        ]);
    }
}
