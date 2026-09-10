@extends('minihouse::portal.layout')

@section('title', 'Tổng quan - Portal khách thuê')

@section('content')
    <h1 class="text-xl font-semibold text-gray-900">Xin chào, {{ $tenant->fullname }}</h1>

    @if ($activeContract)
        <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100">
            <div class="text-sm text-gray-500">Phòng đang thuê</div>
            <div class="mt-1 text-lg font-semibold text-gray-900">
                {{ $activeContract->room?->code ?? '—' }} — {{ $activeContract->room?->building?->name ?? '—' }}
            </div>
            <div class="mt-2 text-sm text-gray-600 space-y-0.5">
                <div>Giá thuê: <strong>{{ number_format((float) $activeContract->monthly_price, 0, ',', '.') }}đ/tháng</strong></div>
                <div>Ngày bắt đầu: {{ $activeContract->start_date?->format('d/m/Y') }}</div>
                @if ($activeContract->end_date)
                    <div>Ngày kết thúc: {{ $activeContract->end_date->format('d/m/Y') }}</div>
                @endif
            </div>
        </div>
    @else
        <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100 text-sm text-gray-500">
            Bạn hiện không có hợp đồng nào đang hiệu lực.
        </div>
    @endif

    <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100">
        <div class="text-sm text-gray-500">Tổng còn phải thanh toán</div>
        <div class="mt-1 text-2xl font-bold {{ $unpaidTotal > 0 ? 'text-red-600' : 'text-green-600' }}">
            {{ number_format($unpaidTotal, 0, ',', '.') }}đ
        </div>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-3">
        <a href="{{ route('minihouse.portal.invoices.index') }}" class="relative rounded-xl bg-white p-4 shadow-sm border border-gray-100 hover:border-gray-300 transition">
            @if ($unpaidInvoiceCount > 0)
                <span class="absolute -top-2 -right-2 inline-flex items-center justify-center min-w-[20px] h-[20px] px-1 rounded-full bg-red-600 text-white text-[11px] font-semibold">
                    {{ $unpaidInvoiceCount > 9 ? '9+' : $unpaidInvoiceCount }}
                </span>
            @endif
            <div class="text-sm font-medium text-gray-900">Hoá đơn</div>
            <div class="mt-0.5 text-xs text-gray-500">Xem &amp; thanh toán</div>
        </a>
        <a href="{{ route('minihouse.portal.contracts.index') }}" class="rounded-xl bg-white p-4 shadow-sm border border-gray-100 hover:border-gray-300 transition">
            <div class="text-sm font-medium text-gray-900">Hợp đồng</div>
            <div class="mt-0.5 text-xs text-gray-500">Chi tiết &amp; tải file</div>
        </a>
        <a href="{{ route('minihouse.portal.payments.index') }}" class="rounded-xl bg-white p-4 shadow-sm border border-gray-100 hover:border-gray-300 transition">
            <div class="text-sm font-medium text-gray-900">Lịch sử thanh toán</div>
            <div class="mt-0.5 text-xs text-gray-500">Toàn bộ giao dịch</div>
        </a>
        <a href="{{ route('minihouse.portal.feedback') }}" class="rounded-xl bg-white p-4 shadow-sm border border-gray-100 hover:border-gray-300 transition">
            <div class="text-sm font-medium text-gray-900">Gửi phản hồi</div>
            <div class="mt-0.5 text-xs text-gray-500">Báo sự cố, góp ý</div>
        </a>
        <a href="{{ route('minihouse.portal.password') }}" class="rounded-xl bg-white p-4 shadow-sm border border-gray-100 hover:border-gray-300 transition">
            <div class="text-sm font-medium text-gray-900">Mật khẩu</div>
            <div class="mt-0.5 text-xs text-gray-500">Đặt / đổi mật khẩu</div>
        </a>
        <a href="{{ route('minihouse.portal.notifications') }}" class="relative rounded-xl bg-white p-4 shadow-sm border border-gray-100 hover:border-gray-300 transition">
            @if ($unreadNotificationCount > 0)
                <span class="absolute -top-2 -right-2 inline-flex items-center justify-center min-w-[20px] h-[20px] px-1 rounded-full bg-red-600 text-white text-[11px] font-semibold">
                    {{ $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount }}
                </span>
            @endif
            <div class="text-sm font-medium text-gray-900">Thông báo</div>
            <div class="mt-0.5 text-xs text-gray-500">Tin mới nhất</div>
        </a>
    </div>

    @if ($building && ($building->owner_name || $building->owner_phone))
        <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100">
            <div class="text-sm font-medium text-gray-900">Liên hệ chủ nhà</div>
            <div class="mt-2 text-sm text-gray-600 space-y-0.5">
                @if ($building->owner_name)
                    <div>{{ $building->owner_name }}</div>
                @endif
                @if ($building->owner_phone)
                    <div>SĐT: <a href="tel:{{ $building->owner_phone }}" class="text-gray-900 font-medium">{{ $building->owner_phone }}</a></div>
                @endif
                @if ($building->address)
                    <div>Địa chỉ: {{ $building->address }}</div>
                @endif
            </div>
        </div>
    @endif
@endsection
