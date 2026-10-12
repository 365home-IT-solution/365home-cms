@php
    extract($this->getViewData());
    // Trước đây ẩn hẳn KPI/Doanh thu với "nhân viên vận hành" (dựa vào isPartnerOwner(), từng bị
    // lỗi hasRole('partner') luôn false — xem User::isPartnerOwner()). Giờ đổi luật: AI ĐƯỢC CẤP
    // QUYỀN vào trang Dashboard (page_Dashboard, xem Dashboard::canAccess()) thì thấy ĐẦY ĐỦ nội
    // dung như nhau (KPI/Doanh thu/biểu đồ) — không còn phân biệt chủ đối tác hay nhân viên nữa,
    // việc ai được xem Dashboard hay không giờ hoàn toàn do quyền page_Dashboard quyết định.
@endphp

<x-filament-panels::page>

<livewire:book::block-timeslot-modal />
<livewire:room-lock-grid />

<div class="ta-wrap">
<div class="ta-inner">

    @include('dashboard::pages.dashboard._header')

    {{-- Super Admin: Của 365home · Tổng quan (mình + đối tác) · Theo đối tác. Chỉ doanh thu; hoa hồng chi tiết ở Quản lý › Thống kê hoa hồng. --}}
    @include('dashboard::pages.dashboard._stats-tabs')

    @if ($this->activeStatsTab() === 'partner')
        @include('dashboard::pages.dashboard._stats-partner-select')
    @endif

    {{-- Thẻ doanh thu đổi theo tab; các phần bên dưới (phòng, biểu đồ, hoa hồng...) giữ nguyên ở mọi tab. --}}
    {{-- Theo đối tác, chọn "Tất cả đối tác": thẻ tóm tắt hoa hồng; chi tiết ở Quản lý › Thống kê hoa hồng. --}}
    @if ($this->activeStatsTab() === 'partner' && blank($statsPartnerId))
        @include('dashboard::pages.dashboard._stats-commission-card')
    @endif

    {{-- Thẻ doanh thu đổi theo tab; các phần bên dưới (phòng, biểu đồ, hoa hồng...) giữ nguyên ở mọi tab. --}}
    @include('dashboard::pages.dashboard._kpi')

    @include('dashboard::pages.dashboard._room-cards')

    @include('dashboard::pages.dashboard._bottom-grid')

</div>
</div>

@include('dashboard::pages.dashboard._scripts')

@include('dashboard::pages.dashboard._styles')

</x-filament-panels::page>
