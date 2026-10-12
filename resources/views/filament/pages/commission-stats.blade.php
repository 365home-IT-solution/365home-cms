<x-filament-panels::page>
    <form wire:submit.prevent="$refresh">{{ $this->form }}</form>

    @include('filament.partials.commission-stats-body', [
        'stats' => $this->getStats(),
        'emptyMessage' => $this->isPlatformSide() ? 'Chọn một đối tác để xem góc nhìn đối tác.' : 'Tài khoản chưa gắn đối tác Homestay.',
    ])
</x-filament-panels::page>
