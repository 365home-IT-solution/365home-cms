<x-filament-panels::page>
    @php
        $colors = [
            'pending_payment' => '#d97706',
            'confirmed'       => '#2563eb',
            'picked_up'       => '#16a34a',
            'overdue'         => '#dc2626',
            'returned'        => '#0891b2',
            'settled'         => '#64748b',
            'block'           => '#6b7280',
        ];
        $statusLabel = \Modules\VehicleRental\App\Filament\Resources\VehicleBookingResource::STATUSES;
    @endphp

    <style>
        .vr-wrap { overflow-x: auto; border: 1px solid rgba(128,128,128,.28); border-radius: .75rem; }
        .vr-grid { min-width: 980px; }
        .vr-row { display: flex; border-top: 1px solid rgba(128,128,128,.22); }
        .vr-row:first-child { border-top: 0; }
        .vr-label { flex: 0 0 190px; padding: .5rem .75rem; border-right: 1px solid rgba(128,128,128,.28); }
        .vr-label b { display: block; font-size: .85rem; line-height: 1.2; }
        .vr-label small { opacity: .65; font-size: .72rem; }
        .vr-track { position: relative; flex: 1 1 auto; height: 46px; background-size: calc(100% / var(--vr-days)) 100%;
            background-image: linear-gradient(to right, rgba(128,128,128,.22) 1px, transparent 1px); }
        .vr-head { background: rgba(128,128,128,.08); }
        .vr-head .vr-track { height: auto; display: flex; background-image: none; }
        .vr-day { flex: 1 1 0; text-align: center; font-size: .7rem; padding: .3rem 0; border-left: 1px solid rgba(128,128,128,.22); line-height: 1.15; }
        .vr-day.weekend { background: rgba(128,128,128,.10); }
        .vr-day.today { color: #dc2626; font-weight: 700; }
        .vr-bar { position: absolute; top: 7px; height: 32px; border-radius: .4rem; color: #fff; font-size: .72rem; line-height: 32px;
            padding: 0 .4rem; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; text-decoration: none; box-shadow: 0 1px 2px rgba(0,0,0,.25); }
        a.vr-bar:hover { filter: brightness(1.12); }
        .vr-bar.block { background-image: repeating-linear-gradient(45deg, rgba(255,255,255,.22) 0 6px, transparent 6px 12px); cursor: default; }
        .vr-bar.pending_payment { background-image: repeating-linear-gradient(45deg, rgba(255,255,255,.2) 0 6px, transparent 6px 12px); }
        .vr-today { position: absolute; top: 0; bottom: 0; width: 2px; background: #dc2626; opacity: .75; pointer-events: none; z-index: 2; }
        .vr-legend { display: flex; flex-wrap: wrap; gap: .9rem; font-size: .75rem; }
        .vr-legend i { display: inline-block; width: .8rem; height: .8rem; border-radius: .2rem; margin-right: .3rem; vertical-align: -1px; }
        .vr-empty { padding: 2rem; text-align: center; opacity: .65; }
    </style>

    <div class="flex flex-wrap items-end gap-3">
        <div class="flex items-center gap-2">
            <x-filament::button size="sm" color="gray" icon="heroicon-m-chevron-left" wire:click="previous">Trước</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="today">Hôm nay</x-filament::button>
            <x-filament::button size="sm" color="gray" icon="heroicon-m-chevron-right" icon-position="after" wire:click="next">Sau</x-filament::button>
        </div>
        <div style="width: 11rem">
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="from" />
            </x-filament::input.wrapper>
        </div>
        <div style="width: 8rem">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="days">
                    <option value="7">7 ngày</option>
                    <option value="14">14 ngày</option>
                    <option value="30">30 ngày</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <div style="width: 9rem">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="type">
                    <option value="">Tất cả loại xe</option>
                    @foreach ($typeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    </div>

    <div class="vr-legend">
        @foreach ($legend as $item)
            <span><i style="background: {{ $colors[$item['key']] }}"></i>{{ $item['label'] }}</span>
        @endforeach
    </div>

    <div class="vr-wrap" style="--vr-days: {{ $days }}">
        <div class="vr-grid">
            <div class="vr-row vr-head">
                <div class="vr-label"><b>Xe</b></div>
                <div class="vr-track">
                    @foreach ($dayHeaders as $d)
                        <div class="vr-day {{ $d['weekend'] ? 'weekend' : '' }} {{ $d['today'] ? 'today' : '' }}">{{ $d['dow'] }}<br>{{ $d['label'] }}</div>
                    @endforeach
                </div>
            </div>

            @forelse ($rows as $row)
                <div class="vr-row" wire:key="vr-{{ $row['id'] }}">
                    <div class="vr-label">
                        <b>{{ $row['plate'] }}</b>
                        <small>{{ $row['brand'] }} {{ $row['model'] }}@if ($row['status'] !== 'available') · {{ $row['status'] === 'maintenance' ? 'bảo dưỡng' : 'ngừng' }}@endif</small>
                    </div>
                    <div class="vr-track">
                        @if ($todayLeft !== null)
                            <span class="vr-today" style="left: {{ $todayLeft }}%"></span>
                        @endif
                        @foreach ($row['bars'] as $bar)
                            @php
                                $key = $bar['kind'] === 'block' ? 'block' : ($bar['overdue'] ? 'overdue' : $bar['status']);
                                $style = "left: {$bar['left']}%; width: {$bar['width']}%; background-color: {$colors[$key]}";
                            @endphp
                            @if ($bar['url'])
                                <a href="{{ $bar['url'] }}" class="vr-bar {{ $key }}" style="{{ $style }}" title="{{ $bar['tooltip'] }}">{{ $bar['label'] }}</a>
                            @else
                                <span class="vr-bar {{ $key }}" style="{{ $style }}" title="{{ $bar['tooltip'] }}">{{ $bar['label'] }}</span>
                            @endif
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="vr-empty">Chưa có xe nào để hiển thị.</div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
