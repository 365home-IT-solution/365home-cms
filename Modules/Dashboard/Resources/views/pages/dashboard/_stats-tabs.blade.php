{{-- Thanh chọn góc nhìn thống kê hoa hồng. Super Admin: Tổng quan · Các đối tác · Chi tiết. Chủ đối tác: Tổng quan · Chi tiết (của chính mình). --}}
@if ($this->canViewStats())
@php
    $tabs = $this->isStatsPlatform()
        ? ['overview' => 'Tổng quan', 'partners' => 'Các đối tác', 'detail' => 'Chi tiết đối tác']
        : ['overview' => 'Tổng quan', 'detail' => 'Chi tiết hoa hồng của tôi'];
@endphp
<div style="display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px;border-bottom:1px solid #e5e7eb;">
    @foreach ($tabs as $key => $label)
        <button type="button" wire:click="setStatsTab('{{ $key }}')"
            style="padding:9px 16px;font-size:0.88rem;font-weight:{{ $statsTab === $key ? '700' : '500' }};border:0;background:transparent;cursor:pointer;
                   color:{{ $statsTab === $key ? '#1f4e79' : '#6b7280' }};border-bottom:2px solid {{ $statsTab === $key ? '#1f4e79' : 'transparent' }};margin-bottom:-1px;">
            {{ $label }}
        </button>
    @endforeach
</div>
@endif
