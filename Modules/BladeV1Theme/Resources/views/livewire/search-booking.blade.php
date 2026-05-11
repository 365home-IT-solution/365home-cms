<div x-data="{ searched: false }">
    <style>
        [x-cloak] {
            display: none !important
        }
    </style>
    <!-- Search Section -->
    <div class="w-full text-center space-y-8 fade-in-up" style="animation-delay: 0.1s;">
        <div class="space-y-3">
            <div
                class="inline-flex items-center gap-1 px-3 py-1 rounded-full border border-gray-200 bg-white/50 text-xs font-medium text-gray-600 mb-2">
                <span style="width: 0.375rem; height: .375rem;" class="rounded-full bg-green-500"></span>
                Tra cứu trực tuyến 24/7
            </div>
            <h1 class="text-4xl sm:text-5xl font-semibold tracking-tight text-gray-900">
                Tra cứu đơn đặt phòng
            </h1>
            <p class="text-lg text-gray-500 font-normal max-w-xl mx-auto leading-relaxed">
                Nhập số điện thoại bạn đã sử dụng khi đặt phòng để xem chi tiết booking, mã check-in và trạng thái
                thanh toán.
            </p>
        </div>

        <!-- Search Form -->
        <div class="max-w-md mx-auto mt-5 relative group">
            <div
                class="absolute -inset-0.5 bg-gradient-to-r from-gray-200 to-gray-300 rounded-xl blur opacity-30 group-hover:opacity-60 transition duration-500">
            </div>
            <form wire:submit.prevent="getBooking" @submit="searched = true"
                class="relative bg-white rounded-xl shadow-sm border border-gray-200 p-1.5 flex items-center transition-shadow focus-within:shadow-md focus-within:ring-1 focus-within:ring-gray-200">
                <div class="pl-4 text-gray-400 flex items-center justify-center">
                    <iconify-icon icon="lucide:smartphone" width="20" stroke-width="1.5"></iconify-icon>
                </div>
                <input
                    id="phone_number"
                    wire:model.live="sdt"
                    @input="searched = $event.target.value.trim() !== '' ? searched : false"
                    type="text"
                    placeholder="Nhập số điện thoại"
                    class="w-full bg-transparent border-none focus:ring-0 text-gray-900 placeholder-gray-400 text-base py-3 px-3 outline-none"
                    required>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    class="bg-primary text-white px-5 py-2.5 rounded-lg text-sm font-medium transition-all shadow-sm hover:shadow flex items-center gap-2 whitespace-nowrap">
                    <span class="flex items-center gap-2">
                        <i wire:loading.remove class="fa fa-search"></i>
                        <span>Tra cứu</span>
                    </span>

                    <span wire:loading wire:target="getBooking" class="flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                    </span>
                </button>
            </form>
        </div>
        @error('sdt')
        <span class="text-red-600 text-sm mb-5">{{ $message }}</span>
        @enderror

        <div class="flex items-center mt-3 justify-center gap-6 text-xs text-gray-400">
            <span class="flex items-center gap-1">
                <i class="fa fa-check-circle"></i>
                Bảo mật thông tin
            </span>
            <span class="flex items-center gap-1">
                <i class="fa fa-bolt"></i>
                Kết quả tức thì
            </span>
        </div>
    </div>


    <div class="max-w-screen-xl md:px-8 px-4 mx-auto">
        <div class="max-w-[700px] min-h-[500px] w-full pt-8 mx-auto">

            {{-- FORM SEARCH --}}

            {{-- RESULT --}}
            <div id="result" class="pt-12 pb-5 relative">
                {{-- Loading overlay shown while searching --}}
                <div wire:loading wire:target="getBooking"
                    class="absolute inset-0 bg-white/70 flex items-center justify-center rounded-xl z-10">
                    <div class="flex items-center justify-center gap-3">
                        <svg class="animate-spin h-6 w-6 text-gray-700" xmlns="http://www.w3.org/2000/svg" fill="none"
                            viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span class="text-gray-700 font-medium">Đang tải kết quả...</span>
                    </div>
                </div>
                @if(!empty($orders) && $orders->count())
                @foreach($orders as $order)
                @php

                $accessCode = $order->accessCode;
                $unlockCode = $accessCode ? $accessCode->code : '....';
                $gateLocation = $accessCode && $accessCode->gate_location ? $accessCode->gate_location : '';
                $branchName = $order->category ? $order->category->name : 'Chi nhánh';
                $create_at = $order->created_at ? \Carbon\Carbon::parse($order->created_at)->format('d/m/Y H:i') :
                'N/A';

                $statusLabels = [
                'deposit' => 'Đã cọc — Chờ thanh toán còn lại',
                'paid' => 'Đã thanh toán',
                'failed' => 'Thanh toán thất bại',
                ];
                $statusVietnamese = $statusLabels[$order->status] ?? $order->status;
                $buyerName = $order->buyer_name ?? 'Khách hàng';

                // deposit + checkout_url IS NOT NULL = chưa thanh toán cọc lần 1
                $isPendingDeposit = $order->status === 'deposit' && !empty($order->checkout_url);
                // deposit + checkout_url IS NULL = đã cọc, chờ thanh toán còn lại
                $isDepositPaid = $order->status === 'deposit' && empty($order->checkout_url);
                $isPaid = $order->status === 'paid';
                $depositPercent = $order->deposit_percent;
                $fullAmount = $order->full_amount ?? $order->amount;
                $paidAmount = $order->amount;
                $remainingAmount = $isDepositPaid ? max(0, $fullAmount - $paidAmount) : 0;

                $firstProduct = $order->items->first() ? $order->items->first()->product : null;
                $branchAddress = $firstProduct ? $firstProduct->address : 'Địa chỉ chi nhánh không xác định';
                $hotline = $firstProduct ? $firstProduct->hotline : '';
                $wifi = $firstProduct ? $firstProduct->wifi : '...';
                @endphp


                <div
                    class="bg-white mb-10 rounded-2xl border border-black shadow-[0_8px_30px_rgb(0,0,0,0.04)] overflow-hidden">
                    <!-- Card Header -->
                    <div class="px-6 py-5 border-b border-gray-100 flex justify-between items-start bg-gray-50/50">
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Mã đặt phòng</p>
                            <div class="flex items-center gap-2">
                                <span class="text-xl font-semibold tracking-tight text-gray-900">{{ $order->order_code }}</span>
                            </div>
                            <span class="text-base font-normal italic tracking-tight text-gray-600">Dear: {{ $buyerName }}</span>
                        </div>
                        <div class="flex flex-col items-end">
                            @if($isDepositPaid)
                            <span class="inline-flex items-center gap-1 px-3 py-2 rounded-full text-amber-700 text-xs font-medium border border-amber-200" style="background-color: rgb(255 251 235);">
                                    💰 Đã cọc {{ $depositPercent }}%
                                </span>
                            @elseif($isPendingDeposit)
                            <span class="inline-flex items-center gap-1 px-3 py-2 rounded-full text-orange-700 text-xs font-medium border border-orange-200" style="background-color: rgb(255 247 237);">
                                    ⏳ Chờ thanh toán cọc {{ $depositPercent }}%
                                </span>
                            @else
                            <span class="inline-flex items-center gap-1 px-3 py-2 rounded-full text-green-700 text-xs font-medium border border-green-100" style="background-color: rgba(243 250 247);">
                                    <i class="fa fa-check-circle"></i>
                                    {{ ucfirst($statusVietnamese) }}
                                </span>
                            @endif
                            <span class="text-sm text-gray-400 mt-1">Đặt ngày {{ $create_at }}</span>
                        </div>
                    </div>

                    <div class="p-6">
                        @foreach($order->items as $item)
                        @php
                        $itemProduct = $item->product;
                        $checkIn = method_exists($item, 'getFormattedCheckinDateAttribute')
                        ? $item->getFormattedCheckinDateAttribute()
                        : ($item->checkin_date ? \Carbon\Carbon::parse($item->checkin_date)->format('d/m/Y H:i') :
                        'N/A');
                        $checkOut = ($item->checkout_date)
                        ? \Carbon\Carbon::parse($item->checkout_date)->format('d/m/Y H:i')
                        : 'N/A';
                        $thumbnailUrl = $itemProduct && $itemProduct->getFirstMedia('Ảnh bìa') ?
                        $itemProduct->getFirstMedia('Ảnh bìa')->getUrl() : 'https://via.placeholder.com/150';
                        $itemGuestCount = $item->guest_count ?? 1;
                        @endphp

                        <div class="@if(!$loop->first) mt-8 pt-8 border-t border-gray-100 @endif">
                            <div class="sm:flex block justify-between items-center">
                                <div class="flex gap-5 mb-4 md:mb-0">
                                    <div
                                        class="w-20 h-20 rounded-lg bg-gray-100 shrink-0 overflow-hidden border border-gray-100">
                                        <img src="{{ $thumbnailUrl }}" alt="Room" class="w-full h-full object-cover">
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="text-lg font-semibold text-gray-900 truncate">{{ $item->name }}</h3>
                                        <p style="text-wrap: auto;" class="text-sm text-gray-500 truncate mt-0.5">Chi
                                            nhánh: {{ $branchName }}</p>
                                        <div class="flex items-center gap-4 mt-3">
                                            <div
                                                class="flex items-center gap-1 text-xs font-medium text-gray-600 bg-gray-100 px-2 py-1 rounded-md">
                                                <i class="fa fa-users"></i>
                                                {{ $itemGuestCount }} Người
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                @if($loop->first)
                                <div class="px-4 flex items-end md:justify-between sm:justify-start gap-3">
                                    <div
                                        class="w-10 h-10 bg-white rounded-lg border border-gray-200 flex items-center justify-center text-blue-600 shadow-sm shrink-0">
                                        <i class="fa fa-wifi"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <div
                                            class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                                            Wifi / Pass
                                        </div>
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-sm font-medium text-gray-900 truncate">365Home_5G</span>
                                            <span class="text-gray-300">/</span>
                                            <span class="text-base font-mono font-semibold text-gray-700">{{ $wifi }}</span>
                                        </div>
                                    </div>
                                </div>
                                @endif
                            </div>

                            <!-- Timeline -->
                            <div class="mt-8 px-1 flex items-center justify-between relative">
                                <div class="absolute top-1/2 left-0 w-full h-px bg-gray-100" style="z-index: -10;">
                                </div>

                                <!-- Check In -->
                                <div class="flex flex-col gap-1 pr-4 bg-white">
                                    <span class="text-xs font-medium text-gray-400 uppercase tracking-wide">Nhận phòng</span>
                                    <div class="flex items-center gap-2">
                                        <i class="fa fa-calendar-check text-gray-400"></i>
                                        <span class="text-sm sm:text-base text-center sm:text-left font-semibold text-gray-900">{{ $checkIn }}</span>
                                    </div>
                                </div>

                                <!-- Arrow -->
                                <div
                                    class="w-8 h-8 rounded-full bg-gray-50 border border-gray-100 flex items-center justify-center text-gray-400 bg-white">
                                    <i class="fas fa-arrow-right"></i>
                                </div>

                                <!-- Check Out -->
                                <div class="flex flex-col gap-1 pl-4 items-end bg-white">
                                    <span class="text-xs font-medium text-gray-400 uppercase tracking-wide">Trả phòng</span>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm sm:text-base text-center sm:text-left font-semibold text-gray-900">{{ $checkOut }}</span>
                                        <i class="fa fa-calendar-times text-gray-400"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endforeach

                        <!-- Gate Unlock Code -->
                        @if($isDepositPaid || $isPendingDeposit)
                        {{-- Đơn cọc: chưa có mã, hiện thông báo chờ thanh toán --}}
                        <div
                            class="mt-6 bg-amber-50 rounded-xl border border-dashed border-amber-300 p-4 flex items-center gap-4">
                            <div
                                class="w-10 h-10 bg-white rounded-lg border border-amber-200 flex items-center justify-center text-amber-500 shadow-sm shrink-0">
                                <i class="fa fa-lock"></i>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-amber-800">Mã mở khóa chưa được cấp</p>
                                <p class="text-xs text-amber-600 mt-0.5">
                                    @if($isPendingDeposit)
                                    Vui lòng hoàn tất thanh toán cọc để tiếp tục.
                                    @else
                                    Mã sẽ được cấp sau khi bạn thanh toán tiền còn lại.
                                    @endif
                                </p>
                            </div>
                        </div>
                        @else
                        <div
                            class="mt-6 bg-slate-50/80 rounded-xl border border-dashed border-gray-300 p-4 flex sm:flex-col sm:flex-row gap-4 sm:items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-10 h-10 bg-white rounded-lg border border-gray-200 flex items-center justify-center text-gray-700 shadow-sm shrink-0">
                                    <i class="fa fa-key"></i>
                                </div>
                                <div>
                                    <div class="gap-2">
                                        <p class="text-sm text-gray-500 truncate mt-0.5">Mã mở khóa</p>
                                        <span class="text-lg font-bold font-mono tracking-widest text-gray-900">{{ $unlockCode }}#</span>
                                    </div>
                                </div>
                            </div>
                            <div class="border-l border-1 border-gray-300 border-dashed h-12"></div>
                            <div class="flex items-center gap-3 pl-0 sm:pl-4 sm:border-l border-gray-200">
                                <div>
                                    <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Lượt
                                        dùng
                                    </div>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <span class="text-sm text-gray-900 font-normal">/ {{ $accessCode->max_uses ?? '3' }} lượt</span>
                                    </div>
                                </div>
                                <div class="mx-auto sm:ml-0">
                                    <span class="flex relative">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-500 opacity-75"></span>
                                    <span style="width: 10px; height: 10px;" class="relative inline-flex rounded-full bg-green-500"></span>
                                    </span>
                                </div>
                            </div>
                        </div>
                        @endif

                        <!-- Branch Address -->
                        <div
                            class="mt-4 bg-amber-50/60 rounded-xl border border-dashed border-amber-200 p-4 flex items-center gap-4">
                            <div
                                class="w-10 h-10 bg-white rounded-lg border border-gray-200 flex items-center justify-center text-amber-500 shadow-sm shrink-0">
                                <i class="fa fa-map-marker-alt"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 mb-0.5">Địa
                                    chỉ chi nhánh</p>
                                <p class="text-sm font-medium text-gray-900 leading-snug">{{ $branchAddress }}</p>
                            </div>
                            <a href="https://www.google.com/maps/search/?q={{ urlencode($branchAddress) }}"
                                target="_blank"
                                class="shrink-0 text-xs font-semibold text-amber-600 underline whitespace-nowrap">
                                Xem bản đồ
                            </a>
                        </div>

                        <!-- Payment Info -->
                        <div style="padding-top: 1.5rem" class="mt-8 border-t border-gray-100 space-y-3">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Nội quy</span>
                                <span class="font-medium text-gray-900">
                                    <a class="text-primary underline font-semibold"
                                        href="{{ url('noi-quy-va-quy-dinh') }}">Xem nội quy tại đây
                                    </a>
                                </span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Hướng dẫn check-in</span>
                                <span class="font-medium text-primary">
                                    <a class="text-primary underline font-semibold"
                                        href="{{ url('huong-dan-tu-check-in') }}">Xem hướng dẫn ngay
                                    </a>
                                </span>
                            </div>

                            @if($isPendingDeposit)
                            {{-- Đơn cọc đang chờ thanh toán lần 1 --}}
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Tổng tiền phòng</span>
                                <span class="font-medium text-gray-900">{{ number_format($fullAmount, 0, ',', '.') }}đ</span>
                            </div>
                            <div class="flex justify-between text-base pt-2 border-t border-dashed border-orange-200">
                                <span class="font-semibold text-orange-800">Cần thanh toán cọc ({{ $depositPercent }}%)</span>
                                <span class="font-bold text-orange-800">{{ number_format($paidAmount, 0, ',', '.') }}đ</span>
                            </div>
                            <div class="pt-2 rounded-xl border-2 border-orange-300 p-4" style="background:#fff7ed;">
                                <p class="font-bold text-orange-800 text-sm mb-1 flex items-center gap-2">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                    </svg>
                                    Thanh toán cọc để giữ phòng
                                </p>
                                <p class="text-xs text-orange-700 mb-3">
                                    Số tiền cọc:
                                    <strong class="text-base">{{ number_format($paidAmount, 0, ',', '.') }}đ</strong>
                                </p>
                                <button wire:click="createDepositPayment('{{ $order->order_code }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="createDepositPayment('{{ $order->order_code }}')"
                                    class="w-full py-3 rounded-lg font-bold text-white text-sm flex items-center justify-center gap-2 transition-opacity disabled:opacity-60 bg-orange-500 hover:bg-orange-600">
                                    <span wire:loading.remove wire:target="createDepositPayment('{{ $order->order_code }}')">💳 Thanh toán cọc qua QR PayOS</span>
                                    <span wire:loading wire:target="createDepositPayment('{{ $order->order_code }}')" class="flex items-center gap-2">
                                        <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                        Đang tạo link...
                                    </span>
                                </button>
                                @if($depositError)
                                <p class="text-red-600 text-xs mt-2 text-center">{{ $depositError }}</p>
                                @endif
                                <p class="text-[11px] text-orange-600 mt-2 text-center italic">Sau khi thanh toán cọc
                                    thành công, đơn sẽ được xác nhận.</p>
                            </div>

                            @elseif($isDepositPaid)
                            {{-- Breakdown cọc --}}
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Tổng tiền phòng</span>
                                <span class="font-medium text-gray-900">{{ number_format($fullAmount, 0, ',', '.') }}đ</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Đã đặt cọc ({{ $depositPercent }}%)</span>
                                <span class="font-medium text-green-700">- {{ number_format($paidAmount, 0, ',', '.') }}đ</span>
                            </div>
                            <div class="flex justify-between text-base pt-2 border-t border-dashed border-amber-200">
                                <span class="font-semibold text-amber-800">Còn lại khi nhận phòng</span>
                                <span class="font-bold text-amber-800 tracking-tight">{{ number_format($remainingAmount, 0, ',', '.') }}đ</span>
                            </div>

                            {{-- Nút thanh toán còn lại (Livewire action) --}}
                            <div class="pt-2 rounded-xl border-2 border-amber-300 p-4" style="background:#fffbeb;">
                                <p class="font-bold text-amber-800 text-sm mb-1 flex items-center gap-2">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                    </svg>
                                    Thanh toán phần còn lại để nhận mã cổng
                                </p>
                                <p class="text-xs text-amber-700 mb-3">
                                    Số tiền cần thanh toán:
                                    <strong class="text-base">{{ number_format($remainingAmount, 0, ',', '.') }}đ</strong>
                                </p>
                                <button wire:click="createRemainingPayment('{{ $order->order_code }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="createRemainingPayment('{{ $order->order_code }}')"
                                    class="w-full py-3 rounded-lg font-bold text-white text-sm flex items-center justify-center gap-2 transition-opacity disabled:opacity-60 bg-amber-500 hover:bg-amber-600">
                                    <span wire:loading.remove wire:target="createRemainingPayment('{{ $order->order_code }}')">💳 Thanh toán qua QR PayOS</span>
                                    <span wire:loading wire:target="createRemainingPayment('{{ $order->order_code }}')" class="flex items-center gap-2">
                                        <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                        Đang tạo link...
                                    </span>
                                </button>
                                @if($remainingError)
                                <p class="text-red-600 text-xs mt-2 text-center">{{ $remainingError }}</p>
                                @endif
                                <p class="text-[11px] text-amber-600 mt-2 text-center italic">Sau khi thanh toán thành
                                    công, mã cổng sẽ hiển thị ngay tại đây.</p>
                            </div>

                            @else
                            <div class="flex justify-between text-base pt-2">
                                <span class="font-semibold text-gray-900">Tổng thanh toán</span>
                                <span class="font-bold text-gray-900 tracking-tight">{{ number_format($order->amount, 0, ',', '.') . 'đ' }}</span>
                            </div>
                            <div style="background-color: rgb(235 245 255 / 0.5);"
                                class="text-blue-700 rounded-lg p-3 flex gap-3 items-start mt-2">
                                <i class="fa fa-info-circle mt-1"></i>
                                <p class="text-xs leading-relaxed">
                                    Bạn đã thanh toán 100%. Vui lòng check-in đúng giờ và mã mở khóa khi đến nhận phòng.
                                    Chúc quý khách có trải nghiệm tuyệt vời tại 365Home!
                                </p>
                            </div>
                            @endif
                        </div>
                    </div>

                    <!-- Footer Actions -->
                    <div class="bg-gray-50 px-6 py-4 flex gap-3 border-t border-gray-200">
                        <a href="https://www.google.com/maps/search/?q={{ urlencode($branchAddress) }}" target="_blank"
                            class="flex-1 bg-white border border-gray-200 text-red-600 hover:bg-gray-50 hover:border-gray-300 hover:text-gray-900 font-medium py-2.5 rounded-lg text-sm transition-all shadow-sm flex items-center justify-center gap-2">
                            <i class="fa fa-map-pin"></i>
                            <button class="hover:underline font-semibold">
                                Định vị Google Map
                            </button>
                        </a>
                    </div>
                    @php
                    $hotlineRaw = $hotline ?? '';
                    $hotlineNumbers = array_filter(array_map('trim', explode('-', $hotlineRaw)));
                    $hotlineLabels = ['Liên hệ Zalo', 'Liên hệ'];
                    @endphp
                    <div class="bg-gray-50 px-6 pb-4 block sm:flex gap-3">
                        @foreach($hotlineNumbers as $idx => $number)
                        <a href="tel:{{ $number }}"
                            class="flex-1 bg-primary m-2 text-white font-medium py-2.5 rounded-lg text-sm transition-all shadow-sm flex items-center justify-center gap-2">
                            <i class="fa fa-message"></i>
                            {{ $hotlineLabels[$idx] ?? 'Liên hệ' }}
                            <span class="text-white font-bold ml-2">{{ $number }}</span>
                        </a>
                        @endforeach
                    </div>
                </div>

                @endforeach
                @elseif($sdt)
                <div x-show="searched" x-cloak class="mt-6 text-gray-500 text-center flex flex-col items-center">
                    <svg class="w-12 h-12 text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z">
                        </path>
                    </svg>
                    <p>Không tìm thấy đơn đặt phòng nào đã thanh toán với số điện thoại này.</p>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>