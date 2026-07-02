<div>
    <section class="py-10 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            {{-- Banner thông báo khuyến mãi --}}
            <div style="background:linear-gradient(90deg,#dc2626,#ea580c,#f59e0b); padding:12px 16px;">
                <div
                    style="max-width:80rem; margin:0 auto; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">

                    {{-- Icon + nội dung --}}
                    <div style="display:flex; align-items:center; gap:10px; flex:1; min-width:0;">
                        {{-- Icon sét --}}
                        <div
                            style="background:rgba(255,255,255,0.2); border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg style="width:16px;height:16px;color:#fff;" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z"
                                    clip-rule="evenodd" />
                            </svg>
                        </div>

                        {{-- Badge + text --}}
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <span
                                style="background:rgba(255,255,255,0.25); color:#fff; font-size:11px; font-weight:800; padding:2px 10px; border-radius:99px; letter-spacing:.05em; text-transform:uppercase; white-space:nowrap;">
                                Flash Sale
                            </span>
                            <span style="color:#fff; font-size:14px; font-weight:600;">
                                Ưu đãi đặc biệt — Giảm đến <strong style="font-size:16px;">30%</strong> tất cả phòng hôm
                                nay!
                            </span>
                            <span style="color:rgba(255,255,255,0.75); font-size:12px;">
                                🕐 Chỉ hôm nay, áp dụng khi đặt trước 22:00
                            </span>
                        </div>
                    </div>

                    {{-- Nút CTA --}}
                    <a href="{{ route('product.search') }}"
                        style="background:#fff; color:#dc2626; font-size:13px; font-weight:700; padding:6px 18px; border-radius:99px; text-decoration:none; white-space:nowrap; flex-shrink:0; box-shadow:0 2px 8px rgba(0,0,0,0.15); transition:opacity .15s;"
                        onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                        Đặt ngay →
                    </a>
                </div>
            </div>
        </div>
    </section>
    {{-- ============================================================
    CODE CŨ (flash sale danh sách sản phẩm) — giữ lại, không xóa
    ================================================================

    <section class="py-10 bg-gradient-to-br from-red-50 via-orange-50 to-yellow-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">

            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2 bg-red-500 text-white px-4 py-2 rounded-full shadow-md">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/>
                        </svg>
                        <span class="font-extrabold text-sm tracking-wider uppercase">Flash Sale</span>
                    </div>
                    <p class="text-gray-500 text-sm font-medium">Giá ưu đãi đặc biệt hôm nay</p>
                </div>
                <a href="{{ route('product.search') }}"
                    class="text-sm font-semibold text-red-500 hover:text-red-600 flex items-center gap-1 transition-colors">
                    Xem tất cả
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>

            <div class="flex gap-4 overflow-x-auto pb-4 scrollbar-hide snap-x snap-mandatory">
                @foreach ($products as $product)
                <a href="{{ route('product.detail', ['slug' => $product['slug']]) }}"
                    class="snap-start flex-none w-44 sm:w-52 bg-white rounded-2xl overflow-hidden shadow-sm hover:shadow-lg transition-all duration-200 group border border-red-100">

                    <div class="relative pt-[70%] overflow-hidden">
                        @if ($product['image'])
                            <img src="{{ $product['image'] }}"
                                alt="{{ $product['name'] }}"
                                class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" />
                        @else
                            <div class="absolute inset-0 bg-gray-100"></div>
                        @endif

                        @if ($product['discount'] > 0)
                        <div class="absolute top-2 left-2 bg-red-500 text-white text-[11px] font-bold px-2 py-0.5 rounded-full shadow">
                            -{{ number_format($product['discount']) }}đ
                        </div>
                        @endif
                    </div>

                    <div class="p-3">
                        <p class="text-xs font-semibold text-gray-800 line-clamp-2 leading-snug min-h-[2.5rem]">
                            {{ $product['name'] }}
                        </p>
                        <p class="text-[11px] text-gray-400 mt-0.5 line-clamp-1">{{ $product['category'] }}</p>
                        <div class="mt-2">
                            <div class="text-xs text-gray-400 line-through">
                                {{ number_format($product['original_price']) }}đ
                                @if ($product['time_unit'])/{{ $product['time_unit'] }}@endif
                            </div>
                            <div class="text-sm font-bold text-red-500">
                                {{ number_format($product['sale_price']) }}đ
                                @if ($product['time_unit'])
                                    <span class="text-xs font-normal text-gray-400">/{{ $product['time_unit'] }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </a>
                @endforeach
            </div>
        </div>
    </section>
--}}

</div>
