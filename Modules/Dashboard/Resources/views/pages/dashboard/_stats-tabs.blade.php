{{-- Super Admin chọn góc nhìn doanh thu: Của 365home · Tổng quan (mình + tất cả đối tác) · Theo đối tác. Chủ đối tác không có thanh này. --}}
@if ($this->canViewStats())
@php
    $tabs = ['own' => 'Doanh thu của 365home', 'overview' => 'Tổng quan', 'partner' => 'Theo đối tác'];
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
