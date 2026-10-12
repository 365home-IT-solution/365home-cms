<x-filament-panels::page>
    <form wire:submit.prevent="$refresh">{{ $this->form }}</form>

    @if ($this->isPartnersView())
        @include('filament.partials.commission-partners-table')
    @else
        @include('filament.partials.commission-stats-body', [
            'stats' => $this->getStats(),
            'emptyMessage' => $this->isPlatformSide() ? 'Chọn một đối tác để xem góc nhìn đối tác.' : 'Tài khoản chưa gắn đối tác Homestay.',
        ])
    @endif
</x-filament-panels::page>
