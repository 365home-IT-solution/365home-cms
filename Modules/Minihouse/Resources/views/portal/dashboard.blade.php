@extends('minihouse::portal.layout')

@section('title', 'Tổng quan - Portal khách thuê')

@section('content')
    @php
        $tiles = [
            ['route' => 'minihouse.portal.invoices.index', 'label' => 'Hoá đơn', 'hint' => 'Xem & thanh toán', 'badge' => $unpaidInvoiceCount],
            ['route' => 'minihouse.portal.contracts.index', 'label' => 'Hợp đồng', 'hint' => 'Chi tiết & tải file'],
            ['route' => 'minihouse.portal.payments.index', 'label' => 'Lịch sử thanh toán', 'hint' => 'Toàn bộ giao dịch'],
            ['route' => 'minihouse.portal.feedback', 'label' => 'Gửi phản hồi', 'hint' => 'Báo sự cố, góp ý'],
            ['route' => 'minihouse.portal.vehicles', 'label' => 'Xe của tôi', 'hint' => 'Khai báo & theo dõi xe'],
            ['route' => 'minihouse.portal.password', 'label' => 'Mật khẩu', 'hint' => 'Đặt / đổi mật khẩu'],
            ['route' => 'minihouse.portal.notifications', 'label' => 'Thông báo', 'hint' => 'Tin mới nhất', 'badge' => $unreadNotificationCount],
        ];
    @endphp

    <div class="flex items-center gap-3">
        <span class="flex h-12 w-12 items-center justify-center rounded-2xl text-white font-bold text-lg mh-heading flex-shrink-0" style="background: var(--mh-primary);">
            {{ mb_strtoupper(mb_substr($tenant->fullname, 0, 1)) }}
        </span>
        <div>
            <p class="text-xs text-gray-500">Xin chào</p>
            <h1 class="text-lg font-bold text-gray-900 mh-heading -mt-0.5">{{ $tenant->fullname }}</h1>
        </div>
    </div>

    <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
        @if ($activeContract)
            <div class="mh-card mh-card-pad">
                <div class="text-xs font-medium text-gray-500">Phòng đang thuê</div>
                <div class="mt-2 text-xl font-bold text-gray-900 mh-heading">{{ $activeContract->room?->code ?? '—' }}</div>
                <div class="text-sm text-gray-500">{{ $activeContract->room?->building?->name ?? '—' }}</div>
                <div class="mt-3 pt-3 border-t text-sm text-gray-600 space-y-1" style="border-color: var(--mh-border);">
                    <div class="flex justify-between"><span class="text-gray-400">Giá thuê</span><strong class="mh-tabular">{{ number_format((float) $activeContract->monthly_price, 0, ',', '.') }}đ/tháng</strong></div>
                    <div class="flex justify-between"><span class="text-gray-400">Bắt đầu</span><span class="mh-tabular">{{ $activeContract->start_date?->format('d/m/Y') }}</span></div>
                    @if ($activeContract->end_date)
                        <div class="flex justify-between"><span class="text-gray-400">Kết thúc</span><span class="mh-tabular">{{ $activeContract->end_date->format('d/m/Y') }}</span></div>
                    @endif
                </div>
            </div>
        @else
            <div class="mh-card mh-card-pad flex items-center text-sm text-gray-500">
                Bạn hiện không có hợp đồng nào đang hiệu lực.
            </div>
        @endif

        <div class="mh-card mh-card-pad flex flex-col justify-between" style="background: var(--mh-primary);">
            <div class="text-xs font-medium text-white/70">Tổng còn phải thanh toán</div>
            <div class="mt-2 text-3xl font-extrabold text-white mh-heading mh-tabular">
                {{ number_format($unpaidTotal, 0, ',', '.') }}đ
            </div>
            @if ($unpaidTotal > 0)
                <a href="{{ route('minihouse.portal.invoices.index') }}" class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-white hover:underline">
                    Xem hoá đơn &rarr;
                </a>
            @else
                <p class="mt-3 text-sm text-white/80">Bạn không có khoản nào còn nợ 🎉</p>
            @endif
        </div>
    </div>

    <div class="mt-6 grid grid-cols-2 sm:grid-cols-3 gap-3">
        @foreach ($tiles as $tile)
            <a href="{{ route($tile['route']) }}" class="mh-tile">
                @if (($tile['badge'] ?? 0) > 0)
                    <span class="absolute -top-1.5 -right-1.5 inline-flex items-center justify-center min-w-[19px] h-[19px] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold">
                        {{ $tile['badge'] > 9 ? '9+' : $tile['badge'] }}
                    </span>
                @endif
                <div class="text-sm font-semibold text-gray-900">{{ $tile['label'] }}</div>
                <div class="text-xs text-gray-400 -mt-1.5">{{ $tile['hint'] }}</div>
            </a>
        @endforeach
    </div>

    @if ($building && ($building->owner_name || $building->owner_phone))
        <div class="mt-6 mh-card mh-card-pad">
            <div class="text-sm font-semibold text-gray-900">Liên hệ chủ nhà</div>
            <div class="mt-2 text-sm text-gray-600 space-y-1">
                @if ($building->owner_name)
                    <div>{{ $building->owner_name }}</div>
                @endif
                @if ($building->owner_phone)
                    <div>SĐT: <a href="tel:{{ $building->owner_phone }}" class="font-semibold" style="color: var(--mh-primary);">{{ $building->owner_phone }}</a></div>
                @endif
                @if ($building->address)
                    <div>Địa chỉ: {{ $building->address }}</div>
                @endif
            </div>
        </div>
    @endif
@endsection
