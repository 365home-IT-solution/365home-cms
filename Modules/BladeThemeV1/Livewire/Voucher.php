<?php

namespace Modules\BladeThemeV1\Livewire;

use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class Voucher extends Component
{
    public array $images = [];

    public function mount(): void
    {
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

        $files = Storage::disk('public')->files('voucher');

        $this->images = collect($files)
            ->filter(fn ($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $allowed))
            ->values()
            ->map(fn ($f) => asset('storage/' . $f))
            ->toArray();
    }

    public function render()
    {
        return view('bladethemev1::livewire.voucher');
    }
}
