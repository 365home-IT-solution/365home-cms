<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Tra cứu đặt phòng - {{ $order->order_code }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        [x-cloak] {
            display: none !important
        }
    </style>
</head>

<body>
    <div class="max-w-screen-xl md:px-8 px-3 mx-auto py-3">
        <div class="max-w-[700px] min-h-[500px] w-full pt-8 mx-auto">
            <div id="result" class="pb-5 relative">

                {{-- Thông báo khi vừa thanh toán cọc thành công --}}
                @if(session('deposit_success'))
                <div class="mb-4 p-4 rounded-xl bg-amber-50 border border-amber-200 flex items-start gap-3">
                    <span class="text-2xl">💰</span>
                    <div>
                        <p class="font-semibold text-amber-800">Thanh toán cọc thành công!</p>
                        <p class="text-sm text-amber-700 mt-0.5">Vui lòng thanh toán số tiền còn lại khi nhận phòng hoặc
                            qua link bên dưới.</p>
                    </div>
                </div>
                @endif

                @php
                // Order-level data
                $accessCode = $order->getPrimaryAccessCode();
                $unlockCode = $accessCode ? $accessCode->code : '....';
                $gateLocation = $accessCode && $accessCode->gate_location ? $accessCode->gate_location : '';
                $branchName = $order->category ? $order->category->name : 'Chi nhánh';
                $create_at = $order->created_at ? \Carbon\Carbon::parse($order->created_at)->format('d/m/Y H:i') :
                'N/A';

                $statusLabels = [
                'pending' => 'Chờ thanh toán',
                'paid' => 'Đã thanh toán',
                'deposit' => 'Đã thanh toán cọc',
                'failed' => 'Thanh toán thất bại',
                'cancelled_payment' => 'Hủy thanh toán',
                ];
                $statusVietnamese = $statusLabels[$order->status] ?? $order->status;
                $isDepositPaid = $order->status === 'deposit';
                $isFullyPaid = $order->status === 'paid';
                $hasDeposit = $order->deposit_percent !== null;
                $buyerName = $order->buyer_name ?? 'Khách hàng';

                // Helper for product info (using first item's product for order-level defaults)
                $firstProduct = $order->items->first() ? $order->items->first()->product : null;
                $manualLockPassword = $firstProduct ? $firstProduct->manualLockPasswords->first() : null;
                $branchAddress = $firstProduct ? $firstProduct->address : 'Địa chỉ chi nhánh không xác định';
                $hotline = $firstProduct ? $firstProduct->hotline : '';
                $wifi = $firstProduct ? $firstProduct->wifi : '...';

                $targetUrl = route('booking.detail', ['code' => $order->order_code]);
                $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&margin=10&data=" .
                urlencode($targetUrl);
                @endphp

                <div
                    class="bg-white mb-5 rounded-2xl border border-gray-200 shadow-[0_8px_30px_rgb(0,0,0,0.04)] overflow-hidden">
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
                            <span class="inline-flex items-center gap-1 px-3 py-2 rounded-full text-amber-700 text-xs font-medium border border-amber-200" style="background-color: rgba(255,251,235);">
                                    💰 Đã cọc {{ $order->deposit_percent }}% — Chờ thanh toán còn lại
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
                                        <!-- <p class="text-sm text-gray-500 text-wrap truncate mt-0.5">Chi nhánh: {{ $branchName }}</p> -->
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
                                        <x-filament::icon-button icon="heroicon-m-wifi" color="blue" size="sm" />
                                    </div>
                                    <div class="min-w-0">
                                        <div
                                            class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                                            Wifi / Pass</div>
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-sm font-medium text-gray-900 truncate">365Home_5G</span>
                                            <span class="text-gray-300">/</span>
                                            <span class="text-sm font-mono font-semibold text-gray-700">{{ $wifi }}</span>
                                            <button class="text-gray-400 hover:text-gray-600 transition-colors p-1 rounded-md hover:bg-gray-200/50">
                                                <iconify-icon icon="lucide:copy" width="14" stroke-width="1.5"></iconify-icon>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                @endif
                            </div>

                            <!-- Timeline -->
                            <div class="mt-8 px-1 flex items-center justify-between relative">
                                <div class="absolute top-1/2 left-0 w-full h-px bg-gray-100" style="z-index: -10;">
                                </div>
                                <div class="flex flex-col gap-1 pr-4 bg-white">
                                    <span class="text-xs font-medium text-gray-400 uppercase tracking-wide">Nhận phòng</span>
                                    <div class="flex items-center gap-2">
                                        <x-filament::icon-button icon="heroicon-m-arrow-right-end-on-rectangle"
                                            color="black" size="xs" />
                                        <span class="text-sm sm:text-base text-center sm:text-left font-semibold text-gray-900">{{ $checkIn }}</span>
                                    </div>
                                </div>
                                <div
                                    class="w-8 h-8 rounded-full bg-gray-50 border border-gray-100 flex items-center justify-center text-gray-400 bg-white">
                                    <x-filament::icon-button icon="heroicon-m-arrow-long-right" color="black"
                                        size="sm" />
                                </div>
                                <div class="flex flex-col gap-1 pl-4 items-end bg-white">
                                    <span class="text-xs font-medium text-gray-400 uppercase tracking-wide">Trả phòng</span>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm sm:text-base text-center sm:text-left font-semibold text-gray-900">{{ $checkOut }}</span>
                                        <x-filament::icon-button icon="heroicon-m-arrow-right-start-on-rectangle"
                                            color="black" size="xs" />
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endforeach

                        <div
                            class="mt-6 bg-slate-50/80 rounded-xl border border-dashed border-gray-300 p-4 flex sm:flex-row gap-4 sm:items-center justify-between">
                            @if($isDepositPaid)
                            {{-- Chưa thanh toán đủ: ẩn mã cổng --}}
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-10 h-10 bg-amber-50 rounded-lg border border-amber-200 flex items-center justify-center text-amber-600 shadow-sm shrink-0">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm text-amber-700 font-semibold">Mã mở khóa chưa khả dụng</p>
                                    <p class="text-xs text-gray-500 mt-0.5">Thanh toán phần còn lại để nhận mã cổng</p>
                                </div>
                            </div>
                            @elseif($firstProduct && $firstProduct->has_manual_lock)
                            {{-- Phòng khóa thủ công: hiển thị pass cổng + pass phòng --}}
                            <div class="flex items-start gap-3 flex-1 flex-wrap">
                                <div
                                    class="w-10 h-10 bg-white rounded-lg border border-gray-200 flex items-center justify-center text-gray-700 shadow-sm shrink-0">
                                    <x-filament::icon-button icon="heroicon-m-key" color="black" size="sm" />
                                </div>
                                @if($manualLockPassword)
                                <div class="flex gap-6 flex-wrap">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Pass Cổng</p>
                                        <span class="text-lg font-bold font-mono tracking-widest text-gray-900">{{ $manualLockPassword->gate_password }}</span>
                                    </div>
                                    @if($manualLockPassword->room_password)
                                    <div class="border-l border-dashed border-blue-400 pl-4">
                                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Pass Phòng</p>
                                        <span class="text-lg font-bold font-mono tracking-widest text-gray-900">{{ $manualLockPassword->room_password }}</span>
                                    </div>
                                    @endif
                                </div>
                                @else
                                <div>
                                    <p class="text-sm text-gray-500 mt-0.5">Vui lòng liên hệ để nhận mật khẩu phòng</p>
                                </div>
                                @endif
                            </div>
                            @else
                            {{-- TTLock: hiển thị mã cổng điện tử --}}
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-10 h-10 bg-white rounded-lg border border-gray-200 flex items-center justify-center text-gray-700 shadow-sm shrink-0">
                                    <x-filament::icon-button icon="heroicon-m-key" color="black" size="sm" />
                                </div>
                                <div>
                                    <div class="gap-2">
                                        <p class="text-sm text-gray-500 truncate mt-0.5">Mã mở khóa</p>
                                        <span class="text-lg font-bold font-mono tracking-widest text-gray-900">{{ $unlockCode }}#</span>
                                    </div>
                                </div>
                            </div>
                            <div class="border-l border-1 border-blue-400 border-dashed h-12"></div>
                            <div class="flex items-center gap-3 pl-0 sm:pl-4 hidden">
                                <div>
                                    <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Lượt
                                        dùng</div>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <span class="text-sm text-gray-900 font-normal">/ {{ $accessCode->max_uses ?? '4' }} lượt</span>
                                    </div>
                                </div>
                                <div class="mx-auto sm:ml-0">
                                    <span class="flex relative">
                                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-500 opacity-75"></span>
                                    <span style="width: 10px; height: 10px;" class="relative inline-flex rounded-full bg-green-500"></span>
                                    </span>
                                </div>
                            </div>
                            @endif
                        </div>

                        {{-- Khung địa chỉ --}}
                        <div
                            class="mt-4 bg-slate-50/80 rounded-xl border border-dashed border-gray-300 p-4 flex sm:flex-row gap-4 sm:items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-10 h-10 bg-white rounded-lg border border-gray-200 flex items-center justify-center text-gray-700 shadow-sm shrink-0">
                                    <x-filament::icon-button icon="heroicon-m-map-pin" color="black" size="sm" />
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500 truncate mt-0.5">Địa chỉ phòng</p>
                                    <span class="text-sm font-semibold text-gray-900">{{ $branchAddress }}</span>
                                </div>
                            </div>
                            <a href="https://maps.app.goo.gl/vREbkzagcBzi99Js9"
                                target="_blank"
                                class="shrink-0 text-xs font-semibold text-blue-600 underline hover:text-blue-800 transition-colors whitespace-nowrap">
                                Xem bản đồ
                            </a>
                        </div>

                        <div style="padding-top: 1.5rem" class="mt-8 border-t border-gray-100 space-y-3">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Nội quy</span>
                                <span class="font-medium text-gray-900">
                                    <a class="text-primary underline font-semibold" href="{{ url('noi-quy-va-quy-dinh') }}">Xem nội quy tại đây</a>
                                </span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Hướng dẫn check-in</span>
                                <span class="font-medium text-primary">
                                    <a class="text-primary underline font-semibold" href="{{ url('huong-dan-tu-check-in') }}">Xem hướng dẫn ngay</a>
                                </span>
                            </div>

                            @if($hasDeposit)
                            @php
                            $fullAmt2 = (int)($order->full_amount ?? $order->amount);
                            $paidAmt2 = (int)$order->amount;
                            $remaining2 = $fullAmt2 - $paidAmt2;
                            $pct2 = (int)$order->deposit_percent;
                            @endphp
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Tổng tiền phòng</span>
                                <span class="font-semibold text-gray-900">{{ number_format($fullAmt2, 0, ',', '.') }}đ</span>
                            </div>
                            @if($isDepositPaid)
                            <div class="flex justify-between text-sm">
                                <span class="text-amber-700 font-semibold">Đã cọc ({{ $pct2 }}%)</span>
                                <span class="font-bold text-amber-700">{{ number_format($paidAmt2, 0, ',', '.') }}đ ✓</span>
                            </div>
                            <div class="flex justify-between text-base pt-1 border-t border-dashed border-amber-200">
                                <span class="font-semibold text-red-600">Còn lại cần thanh toán</span>
                                <span class="font-bold text-red-600">{{ number_format($remaining2, 0, ',', '.') }}đ</span>
                            </div>
                            @else
                            <div class="flex justify-between text-sm">
                                <span class="text-amber-700 font-semibold">Đã cọc ({{ $pct2 }}%)</span>
                                <span class="font-bold text-amber-700">{{ number_format($paidAmt2, 0, ',', '.') }}đ ✓</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-green-700 font-semibold">Đã thanh toán đủ</span>
                                <span class="font-bold text-green-700">✓ Hoàn tất</span>
                            </div>
                            @endif
                            @else
                            <div class="flex justify-between text-base pt-2">
                                <span class="font-semibold text-gray-900">Tổng thanh toán</span>
                                <span class="font-bold text-gray-900 tracking-tight">{{ number_format($order->amount, 0, ',', '.') . 'đ' }}</span>
                            </div>
                            @endif

                            @if($isDepositPaid)
                            {{-- KHỐI THANH TOÁN TIỀN CÒN LẠI --}}
                            <div id="remaining-payment-block" class="rounded-xl border-2 border-amber-300 p-4 mt-3"
                                style="background:#fffbeb;" x-data="{
                                         loading: false,
                                         checkoutUrl: '{{ $order->remaining_checkout_url ?? '' }}',
                                         error: '',
                                         async createPayment() {
                                             if (this.checkoutUrl) { window.location.href = this.checkoutUrl; return; }
                                             this.loading = true;
                                             this.error = '';
                                             try {
                                                 const res = await fetch('{{ route('payment.remaining.create', $order->order_code) }}', {
                                                     method: 'POST',
                                                     headers: {
                                                         'Content-Type': 'application/json',
                                                         'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]') ? document.querySelector('meta[name=csrf-token]').content : ''
                                                     },
                                                 });
                                                 const json = await res.json();
                                                 if (json.error === 0 && json.checkoutUrl) {
                                                     this.checkoutUrl = json.checkoutUrl;
                                                     window.location.href = json.checkoutUrl;
                                                 } else {
                                                     this.error = json.message || 'Không thể tạo link thanh toán.';
                                                 }
                                             } catch(e) {
                                                 this.error = 'Lỗi kết nối, vui lòng thử lại.';
                                             }
                                             this.loading = false;
                                         }
                                     }" x-init="
                                         @if(request()->query('remaining_paid') == 1)
                                             // Trang quay lại sau khi trả tiền còn lại, reload để cập nhật trạng thái
                                             setTimeout(() => window.location.reload(), 2000);
                                         @endif
                                     ">
                                <p class="font-bold text-amber-800 text-sm mb-1 flex items-center gap-1">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                    </svg>
                                    Thanh toán phần còn lại để nhận mã cổng
                                </p>
                                <p class="text-xs text-amber-700 mb-3">
                                    Số tiền cần thanh toán:
                                    <strong class="text-base">{{ number_format($remaining2, 0, ',', '.') }}đ</strong>
                                </p>
                                <button @click="createPayment()"
                                            :disabled="loading"
                                            class="w-full py-3 rounded-lg font-bold text-white text-sm flex items-center justify-center gap-2 transition-opacity disabled:opacity-60"
                                            style="background:#4e6b4c;">
                                        <span x-show="!loading">💳 Thanh toán qua QR PayOS</span>
                                        <span x-show="loading" class="flex items-center gap-2">
                                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                            Đang tạo link...
                                        </span>
                                    </button>
                                <p x-show="error" x-text="error" class="text-red-600 text-xs mt-2 font-medium"
                                    style="display:none;"></p>
                                <p class="text-[11px] text-amber-600 mt-2 text-center italic">Sau khi thanh toán thành
                                    công, mã cổng sẽ hiển thị ngay tại đây.</p>
                            </div>
                            @elseif($isFullyPaid && $hasDeposit)
                            <div style="background-color: rgb(235 245 255 / 0.5);"
                                class="text-green-700 rounded-lg p-3 flex gap-3 items-start mt-2">
                                <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p class="text-xs leading-relaxed">Bạn đã thanh toán đủ (cọc + còn lại). Mã cổng đã được
                                    kích hoạt. Chúc quý khách có trải nghiệm tuyệt vời tại 365Home!</p>
                            </div>
                            @else
                            <div style="background-color: rgb(235 245 255 / 0.5);"
                                class="text-blue-700 rounded-lg p-3 flex gap-3 items-start mt-2">
                                <x-filament::icon-button icon="heroicon-c-information-circle" color="white" size="sm" />
                                <p class="text-xs leading-relaxed">Bạn đã thanh toán 100%. Vui lòng check-in đúng giờ và
                                    mã mở khóa khi đến nhận phòng. Chúc quý khách có trải nghiệm tuyệt vời tại 365Home!
                                </p>
                            </div>
                            @endif
                        </div>
                    </div>

                    <div class="bg-gray-50 px-6 py-4 flex gap-3 border-t border-gray-200">
                        <a href="https://www.google.com/maps/search/?q={{ urlencode($branchAddress) }}" target="_blank"
                            class="flex-1 bg-white border border-gray-200 text-red-600 hover:bg-gray-50 hover:border-gray-300 hover:text-gray-900 font-medium py-2.5 rounded-lg text-sm transition-all shadow-sm flex items-center justify-center gap-2">
                            <x-filament::icon-button icon="heroicon-m-map-pin" x-on:click="open = !open"
                                :tooltip="trans('menu::menu-builder.items.expand')" color="red"
                                class="transition duration-200 ease-in-out" x-bind:class="{ 'rotate-90': open }"
                                size="sm" />
                            <button class="hover:underline font-semibold">Định vị Google Map</button>
                        </a>
                    </div>

                    @php
                    $hotlineRaw = $hotline ?? '';
                    $hotlineNumbers = array_filter(array_map('trim', explode('-', $hotlineRaw)));
                    $hotlineLabels = ['Liên hệ Zalo', 'Liên hệ phone'];
                    @endphp
                    <div class="bg-gray-50 px-6 pb-2 block sm:flex gap-3">
                        @foreach($hotlineNumbers as $idx => $number)
                        <a href="tel:{{ $number }}" style="background-color: #4e6b4c;"
                            class="flex-1 text-white m-1 font-medium py-2.5 rounded-lg text-sm transition-all shadow-sm flex items-center justify-center gap-2">
                            <x-filament::icon-button icon="heroicon-m-chat-bubble-left" x-on:click="open = !open"
                                :tooltip="trans('menu::menu-builder.items.expand')" color="white"
                                class="transition duration-200 ease-in-out" x-bind:class="{ 'rotate-90': open }"
                                size="xs" />
                            {{ $hotlineLabels[$idx] ?? 'Liên hệ'
                            }}<span class="text-white font-bold ml-2">{{ $number }}</span>
                        </a>
                        @endforeach
                    </div>
                    <div class="bg-gray-50 px-6 pb-4 block sm:flex gap-3">
                        <a href="/" style="background-color: #4e6b4c;"
                            class="flex-1 text-white font-medium py-2.5 rounded-lg text-sm transition-all shadow-sm flex items-center justify-center gap-2">
                            <x-filament::icon-button icon="heroicon-m-home" x-on:click="open = !open"
                                :tooltip="trans('menu::menu-builder.items.expand')" color="white"
                                class="transition duration-200 ease-in-out" x-bind:class="{ 'rotate-90': open }"
                                size="xs" />
                            <span class="text-white font-bold ml-2">Trở về trang chủ</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>