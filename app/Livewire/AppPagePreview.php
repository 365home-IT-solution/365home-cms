<?php

namespace App\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;

class AppPagePreview extends Component
{
    public array $blocks = [];

    public function mount(array $initialBlocks = []): void
    {
        $this->blocks = $initialBlocks;
    }

    #[On('app-preview-updated')]
    public function onUpdated(array $blocks): void
    {
        $this->blocks = $blocks;
    }

    public function render()
    {
        return view('livewire.app-page-preview');
    }
}
