{{--
    "Các chi nhánh tại {khu vực}" — bản server-render, KHÔNG còn phụ thuộc API /api/v1/home (dùng
    chung với app mobile). Khu vực chỉ có ở phía client (localStorage) nên div gốc luôn có mặt
    (Livewire yêu cầu đúng 1 root element), còn <section> thật bên trong chỉ hiện khi đã có
    $branches (tức đã nhận được province từ client qua setProvince() — xem BranchSuggestion.php).
--}}
<div
    x-data
    x-init="
        const savedId = localStorage.getItem('home_province_id');
        const savedName = localStorage.getItem('home_province_name');
        if (savedId) { $wire.setProvince(savedId, savedName); }
    "
    x-on:province-selected.window="$wire.setProvince($event.detail.id, $event.detail.name)"
>
    @if (!empty($branches))
        <section class="py-4 bg-white">
            <div class="w-full max-w-7xl mx-auto px-4 sm:px-6" x-data="carouselNav()" x-init="init()">
                <div style="margin-bottom:14px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                        <h2 class="hs-section-title" style="font-weight:800; color:#111827; margin:0;">Các chi nhánh tại {{ $provinceName }}</h2>
                        <div class="hidden lg:flex" style="align-items:center; gap:6px; flex-shrink:0;">
                            <button type="button" class="carousel-nav-btn" aria-label="Trước" x-show="canScrollPrev" @click="prev()">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            </button>
                            <button type="button" class="carousel-nav-btn" aria-label="Tiếp" x-show="canScrollNext" @click="next()">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </div>
                    <a href="{{ $this->viewAllUrl ?? route('product.search') }}" style="display:inline-flex; align-items:center; gap:4px; margin-top:6px; font-size:13px; font-weight:600; color:#1f2937; text-decoration:underline; text-underline-offset:3px;">
                        Xem tất cả
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:14px; height:14px;">
                          <path fill-rule="evenodd" d="M16.72 7.72a.75.75 0 0 1 1.06 0l3.75 3.75a.75.75 0 0 1 0 1.06l-3.75 3.75a.75.75 0 1 1-1.06-1.06l2.47-2.47H3a.75.75 0 0 1 0-1.5h16.19l-2.47-2.47a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                        </svg>
                    </a>
                </div>
                <div x-ref="track" style="display:flex; gap:14px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; padding-bottom:4px;" class="hide-scrollbar">
                    @foreach ($branches as $branch)
                        <a href="/branch/{{ $branch['slug'] }}" class="home-card" style="scroll-snap-align:start; display:flex; flex-direction:column; gap:8px; text-decoration:none;">
                            <div style="position:relative; padding-top:72%; overflow:hidden; background:#f3f4f6; border-radius:14px; flex-shrink:0;">
                                @if ($branch['image_url'])
                                    <img src="{{ $branch['image_url'] }}" alt="" loading="lazy" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">
                                @endif
                            </div>
                            <p style="font-size:13px; font-weight:600; color:#111827; margin:0; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">{{ $branch['name'] }}</p>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</div>
