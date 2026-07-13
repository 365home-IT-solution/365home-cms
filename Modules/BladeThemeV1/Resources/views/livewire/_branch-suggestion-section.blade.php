{{--
    "Các chi nhánh tại {khu vực}" — carousel chi nhánh theo suggestion_type === 'branch'.
    Include ở 2 vị trí khác nhau tuỳ breakpoint (xem flash-sale.blade.php): mobile giữ vị trí cũ
    (ngay trên "Loại hình dịch vụ + Banner"), desktop chuyển xuống dưới "Lịch đặt phòng trực
    tuyến" — mỗi lần include truyền $visibilityClass khác nhau (vd 'lg:hidden' / 'hidden lg:block')
    để chỉ 1 trong 2 bản hiện ra tuỳ kích thước màn hình, tránh phải viết trùng logic CSS order.
    Dùng x-for lọc trực tiếp trên sections (thay vì thêm biến/computed mới trong homeSections())
    — chỉ khớp đúng 1 phần tử (nếu CMS có cấu hình), không có thì không hiện gì cả.
--}}
<template x-for="section in sections.filter(s => s.type === 'suggestion_list' && s.suggestion_type === 'branch' && s.items && s.items.length)" :key="'branch-sugg-{{ $key ?? 'x' }}-' + section.id">
    <section class="py-4 bg-white {{ $visibilityClass ?? '' }}">
        <div class="w-full max-w-7xl mx-auto px-4 sm:px-6" x-data="carouselNav()" x-init="init()">
            <div style="margin-bottom:14px;">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                    <h2 style="font-size:1.1rem; font-weight:800; color:#111827; margin:0;" x-text="provinceName ? ('Các chi nhánh tại ' + provinceName) : 'Các chi nhánh'"></h2>
                    <div class="hidden lg:flex" style="align-items:center; gap:6px; flex-shrink:0;">
                        <button type="button" class="carousel-nav-btn" aria-label="Trước" x-show="canScrollPrev" @click="prev()">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <button type="button" class="carousel-nav-btn" aria-label="Tiếp" x-show="canScrollNext" @click="next()">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </div>
                {{-- "Xem tất cả" dạng chữ + icon + gạch chân, nằm dưới tiêu đề (thay cho nút icon
                     tròn trước đây). --}}
                <a :href="section.view_all_url || '{{ route('product.search') }}'" style="display:inline-flex; align-items:center; gap:4px; margin-top:6px; font-size:13px; font-weight:600; color:#1f2937; text-decoration:underline; text-underline-offset:3px;">
                    Xem tất cả
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:14px; height:14px;">
                      <path fill-rule="evenodd" d="M16.72 7.72a.75.75 0 0 1 1.06 0l3.75 3.75a.75.75 0 0 1 0 1.06l-3.75 3.75a.75.75 0 1 1-1.06-1.06l2.47-2.47H3a.75.75 0 0 1 0-1.5h16.19l-2.47-2.47a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                    </svg>
                </a>
            </div>
            <div x-ref="track" style="display:flex; gap:14px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; padding-bottom:4px;" class="hide-scrollbar">
                <template x-for="item in section.items" :key="'sugg-{{ $key ?? 'x' }}-' + (item.id ?? item.slug)">
                    <div x-html="branchCardHtml(item)"></div>
                </template>
            </div>
        </div>
    </section>
</template>
