<div x-data="{ searched: false }">
    <style>
        [x-cloak] {
            display: none !important
        }
    </style>
    <div class="w-full max-w-11xl mx-auto px-4 sm:px-6">
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
                class="absolute -inset-0.5 bg-gradient-to-r from-gray-200 to-gray-300 rounded-2xl blur opacity-30 group-hover:opacity-60 transition duration-500">
            </div>
            {{-- flex-col trên mobile (input và nút xếp dọc, nút rộng hết cỡ — tránh bị tràn/cắt chữ
                 như thiết kế cũ), quay lại 1 hàng ngang từ sm trở lên. box-shadow inset tạo cảm
                 giác ô nhập liệu hơi lõm vào, tách biệt rõ với nút bấm nổi lên phía trên nó. --}}
            <form wire:submit.prevent="getBooking" @submit="searched = true"
                class="relative bg-white rounded-2xl border border-gray-200 p-2 flex flex-col sm:flex-row gap-2 transition-shadow focus-within:ring-1 focus-within:ring-gray-200"
                style="box-shadow: inset 0 2px 5px rgba(0,0,0,.05), inset 0 0 0 1px rgba(0,0,0,.02);">
                <div class="flex items-center flex-1 min-w-0 rounded-xl">
                    <div class="pl-3 text-gray-400 flex items-center justify-center shrink-0">
                        <iconify-icon icon="lucide:smartphone" width="20" stroke-width="1.5"></iconify-icon>
                    </div>
                    <input
                        id="phone_number"
                        wire:model.live="sdt"
                        @input="searched = $event.target.value.trim() !== '' ? searched : false"
                        type="text"
                        placeholder="Nhập số điện thoại"
                        class="w-full min-w-0 bg-transparent border-none focus:ring-0 text-gray-900 placeholder-gray-400 text-base py-2.5 px-3 outline-none"
                        required>
                </div>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    class="w-full sm:w-auto shrink-0 bg-primary text-white px-5 py-2.5 rounded-xl text-sm font-medium transition-all shadow-sm hover:shadow flex items-center justify-center gap-2 whitespace-nowrap">
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


    <div class="w-full min-h-[500px] pt-8">

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
                {{-- Nền xám nhạt bao quanh để card trắng nổi lên, đồng thời làm màu "khoét" ở
                     đường xé vé (perforation) khớp với nền xung quanh card. --}}
                <div class="bg-gray-50 rounded-3xl p-4 sm:p-6">
                {{-- Cố định số card/hàng theo breakpoint (không dùng auto-fill — số cột phụ thuộc
                     bề rộng cửa sổ thực tế, ở màn hẹp vẫn có thể chỉ ra 1 cột dù còn dư chỗ). Lên
                     hẳn 4 cột ở xl (≥1280px) — thử ở lg (1024px, card ~230px) bị chật: nhãn "Trả
                     phòng" bị cắt, tên phòng/địa chỉ vỡ dòng xấu — nên giữ 3 cột tới hết lg. --}}
                <div class="grid gap-5 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
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

                $firstItem = $order->items->first();
                $firstProduct = $firstItem ? $firstItem->product : null;
                $checkinDate = $firstItem && $firstItem->checkin_date ? \Carbon\Carbon::parse($firstItem->checkin_date) : null;
                $manualLockPassword = $firstProduct && $firstProduct->has_manual_lock
                    ? \Modules\Product\App\Models\ManualLockPassword::getForProductAndDate($firstProduct, $checkinDate)
                    : null;
                $branchAddress = $firstProduct ? $firstProduct->address : 'Địa chỉ chi nhánh không xác định';
                $mapUrl = $firstProduct && $firstProduct->map_url ? $firstProduct->map_url : 'https://www.google.com/maps/search/?q=' . urlencode($branchAddress);
                $wifi = $firstProduct ? $firstProduct->wifi : '...';
                @endphp


                <div
                    class="bg-white rounded-3xl border border-gray-100 shadow-[0_4px_24px_rgba(0,0,0,0.07)] overflow-hidden flex flex-col relative">
                    {{-- Thanh màu primary của theme ở đỉnh card, ngay trên mã đặt phòng --}}
                    <div class="h-1.5 w-full bg-primary"></div>

                    <!-- Card Header -->
                    <div class="px-5 pt-4 pb-3 flex justify-between items-start">
                        <div>
                            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-widest mb-1">Mã đặt phòng</p>
                            <span class="text-xl font-bold tracking-tight text-gray-900">{{ $order->order_code }}</span>
                            <p class="text-xs text-gray-500 italic mt-0.5">Dear: {{ $buyerName }}</p>
                        </div>
                        <div class="flex flex-col items-end shrink-0">
                            @if($isDepositPaid)
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-700">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span> Đã cọc {{ $depositPercent }}%
                            </span>
                            @elseif($isPendingDeposit)
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-orange-700">
                                <span class="w-1.5 h-1.5 rounded-full bg-orange-400"></span> Chờ cọc {{ $depositPercent }}%
                            </span>
                            @else
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> {{ ucfirst($statusVietnamese) }}
                            </span>
                            @endif
                            <span class="text-[11px] text-gray-400 mt-1 whitespace-nowrap">{{ $create_at }}</span>
                        </div>
                    </div>

                    <div class="px-5">
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

                        <div class="@if(!$loop->first) mt-4 pt-4 border-t border-gray-100 @endif">
                            <div class="block">
                                <div class="flex gap-3 items-center">
                                    <div
                                        class="w-14 h-14 rounded-2xl bg-gray-100 shrink-0 overflow-hidden">
                                        <img src="{{ $thumbnailUrl }}" alt="Room" class="w-full h-full object-cover">
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="text-base font-bold text-gray-900 truncate">{{ $item->name }}</h3>
                                        <p style="text-wrap: auto;" class="text-xs text-gray-500 truncate mt-0.5">{{ $branchName }} · {{ $itemGuestCount }} khách</p>
                                    </div>
                                </div>

                                @if($loop->first)
                                <div class="mt-3 flex items-center gap-2 text-xs text-gray-500">
                                    <i class="fa fa-wifi text-blue-500"></i>
                                    <span class="font-medium text-gray-700">365Home_5G</span>
                                    <span class="text-gray-300">·</span>
                                    <span class="font-mono font-semibold text-gray-700">{{ $wifi }}</span>
                                </div>
                                @endif
                            </div>

                            <!-- Timeline kiểu vé máy bay: 2 mốc giờ nối bằng đường chấm -->
                            <div class="mt-4 flex items-center gap-2">
                                <div class="text-left">
                                    <span class="block text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Nhận phòng</span>
                                    <span class="block text-sm font-bold text-gray-900 whitespace-nowrap">{{ $checkIn }}</span>
                                </div>
                                <div class="flex-1 flex items-center gap-0.5 px-1">
                                    <span class="w-1 h-1 rounded-full bg-gray-300"></span>
                                    <span class="flex-1 border-t border-dotted border-gray-300"></span>
                                    <i class="fa fa-key text-[10px] text-gray-400"></i>
                                    <span class="flex-1 border-t border-dotted border-gray-300"></span>
                                    <span class="w-1 h-1 rounded-full bg-gray-300"></span>
                                </div>

                                <div class="text-right">
                                    <span class="block text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Trả phòng</span>
                                    <span class="block text-sm font-bold text-gray-900 whitespace-nowrap">{{ $checkOut }}</span>
                                </div>
                            </div>
                        </div>
                        @endforeach

                        {{-- Đường xé vé (perforation) — tách phần thông tin phòng khỏi "cuống vé"
                             (mã mở khóa), 2 chấm tròn khoét ở mép card khớp màu nền bg-gray-50 bao
                             ngoài để tạo ảo giác lỗ đục như vé giấy thật. --}}
                        <div class="relative -mx-5 my-4">
                            <div class="border-t border-dashed border-gray-200"></div>
                            <span class="absolute -left-2.5 top-1/2 -translate-y-1/2 w-5 h-5 rounded-full bg-gray-50"></span>
                            <span class="absolute -right-2.5 top-1/2 -translate-y-1/2 w-5 h-5 rounded-full bg-gray-50"></span>
                        </div>

                        <!-- Gate Unlock Code -->
                        @if($isDepositPaid || $isPendingDeposit)
                        {{-- Đơn cọc: chưa có mã, hiện thông báo chờ thanh toán --}}
                        <div class="flex items-center gap-3">
                            <div
                                class="w-9 h-9 bg-amber-50 rounded-full flex items-center justify-center text-amber-500 shrink-0">
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
                        @elseif($firstProduct && $firstProduct->has_manual_lock)
                        {{-- Phòng khóa thủ công: hiển thị pass cổng + pass phòng --}}
                        <div class="text-center">
                            @if($manualLockPassword)
                            <div class="flex items-center justify-center gap-6 flex-wrap">
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-widest text-gray-400">Pass Cổng</p>
                                    <span class="text-2xl font-mono font-black tracking-[0.2em] text-gray-900">{{ $manualLockPassword->gate_password }}</span>
                                </div>
                                @if($manualLockPassword->room_password)
                                <div>
                                    <p class="text-[10px] font-semibold uppercase tracking-widest text-gray-400">Pass Phòng</p>
                                    <span class="text-2xl font-mono font-black tracking-[0.2em] text-gray-900">{{ $manualLockPassword->room_password }}</span>
                                </div>
                                @endif
                            </div>
                            @else
                            <p class="text-sm text-gray-500">Vui lòng liên hệ để nhận mật khẩu phòng</p>
                            @endif
                        </div>
                        @else
                        {{-- TTLock: mã cổng điện tử — hiển thị lớn, nổi bật kiểu số hiệu trên vé
                             máy bay, đây là thông tin quan trọng nhất khách cần khi tra cứu. --}}
                        <div class="text-center">
                            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-widest">Mã mở khóa</p>
                            <p class="text-3xl font-mono font-black tracking-[0.25em] text-gray-900 mt-1">{{ $unlockCode }}#</p>
                        </div>
                        @endif

                        <!-- Branch Address -->
                        <div class="mt-4 flex items-start gap-2.5 text-xs">
                            <i class="fa fa-map-marker-alt text-amber-500 mt-0.5 shrink-0"></i>
                            <p class="flex-1 min-w-0 text-gray-600 leading-snug">{{ $branchAddress }}</p>
                            <a href="{{ $mapUrl }}"
                                target="_blank"
                                class="shrink-0 font-semibold text-amber-600 underline whitespace-nowrap">
                                Bản đồ
                            </a>
                        </div>

                        <!-- Payment Info -->
                        <div class="mt-5 pt-4 border-t border-gray-100 space-y-3">
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
                    <div class="bg-gray-50 px-5 py-3 flex gap-3 border-t border-gray-200 mt-auto">
                        <a href="{{ $mapUrl }}" target="_blank"
                            class="flex-1 bg-white border border-gray-200 text-red-600 hover:bg-gray-50 hover:border-gray-300 hover:text-gray-900 font-medium py-2.5 rounded-lg text-sm transition-all shadow-sm flex items-center justify-center gap-2">
                            <button class="hover:underline font-semibold">
                                Định vị Google Map
                            </button>
                        </a>
                    </div>
                </div>

                @endforeach
                </div>
                </div>
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